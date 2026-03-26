<?php
/**
 * CloudiLMS - Modelo de Trilhas de Aprendizagem
 */
require_once __DIR__ . '/database.php';

class TrailModel {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    // ── Trilhas ──────────────────────────────────────────────────────────────

    public function getAllTrails(): array {
        return $this->db->query(
            'SELECT t.*,
                    (SELECT COUNT(*) FROM trail_courses tc WHERE tc.trail_id = t.id) AS course_count,
                    (SELECT COUNT(*) FROM user_trails ut WHERE ut.trail_id = t.id) AS user_count
             FROM trails t ORDER BY t.created_at DESC'
        )->fetchAll();
    }

    public function getTrailById(int $id): ?array {
        $stmt = $this->db->prepare('SELECT * FROM trails WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function createTrail(array $data): int {
        $this->db->prepare(
            'INSERT INTO trails (title, description, created_at) VALUES (?, ?, NOW())'
        )->execute([$data['title'], $data['description'] ?? '']);
        return (int)$this->db->lastInsertId();
    }

    public function updateTrail(int $id, array $data): void {
        $this->db->prepare(
            'UPDATE trails SET title = ?, description = ? WHERE id = ?'
        )->execute([$data['title'], $data['description'] ?? '', $id]);
    }

    public function deleteTrail(int $id): void {
        $this->db->prepare('DELETE FROM trails WHERE id = ?')->execute([$id]);
    }

    // ── Cursos da Trilha ─────────────────────────────────────────────────────

    public function getCoursesByTrail(int $trailId): array {
        $stmt = $this->db->prepare(
            'SELECT c.*, tc.sort_order
             FROM trail_courses tc
             JOIN courses c ON c.id = tc.course_id
             WHERE tc.trail_id = ?
             ORDER BY tc.sort_order ASC, c.title ASC'
        );
        $stmt->execute([$trailId]);
        return $stmt->fetchAll();
    }

    public function addCourseToTrail(int $trailId, int $courseId): void {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM trail_courses WHERE trail_id = ?'
        );
        $stmt->execute([$trailId]);
        $order = (int)$stmt->fetchColumn();
        $this->db->prepare(
            'INSERT IGNORE INTO trail_courses (trail_id, course_id, sort_order) VALUES (?, ?, ?)'
        )->execute([$trailId, $courseId, $order]);
    }

    public function removeCourseFromTrail(int $trailId, int $courseId): void {
        $this->db->prepare(
            'DELETE FROM trail_courses WHERE trail_id = ? AND course_id = ?'
        )->execute([$trailId, $courseId]);
    }

    public function reorderTrailCourses(int $trailId, array $orderedIds): void {
        $stmt = $this->db->prepare(
            'UPDATE trail_courses SET sort_order = ? WHERE trail_id = ? AND course_id = ?'
        );
        foreach ($orderedIds as $pos => $courseId) {
            $stmt->execute([$pos, $trailId, (int)$courseId]);
        }
    }

    /** Cursos ainda não na trilha (para o select de adição) */
    public function getCoursesNotInTrail(int $trailId): array {
        $stmt = $this->db->prepare(
            'SELECT id, title FROM courses
             WHERE id NOT IN (SELECT course_id FROM trail_courses WHERE trail_id = ?)
             ORDER BY title ASC'
        );
        $stmt->execute([$trailId]);
        return $stmt->fetchAll();
    }

    // ── Usuários × Trilhas ───────────────────────────────────────────────────

    public function getUserTrails(int $userId): array {
        $stmt = $this->db->prepare(
            'SELECT t.*, ut.status, ut.assigned_at,
                    (SELECT COUNT(*) FROM trail_courses tc WHERE tc.trail_id = t.id) AS course_count
             FROM user_trails ut
             JOIN trails t ON t.id = ut.trail_id
             WHERE ut.user_id = ?
             ORDER BY ut.assigned_at DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function getTrailUsers(int $trailId): array {
        $stmt = $this->db->prepare(
            'SELECT u.id, u.name, u.email, ut.status, ut.assigned_at
             FROM user_trails ut
             JOIN users u ON u.id = ut.user_id
             WHERE ut.trail_id = ?
             ORDER BY u.name ASC'
        );
        $stmt->execute([$trailId]);
        return $stmt->fetchAll();
    }

    public function assignTrail(int $userId, int $trailId, string $status, int $assignedBy): void {
        $status = in_array($status, ['unlocked', 'locked'], true) ? $status : 'unlocked';
        $this->db->prepare(
            'INSERT INTO user_trails (user_id, trail_id, status, assigned_by, assigned_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE status = VALUES(status), assigned_by = VALUES(assigned_by), assigned_at = NOW()'
        )->execute([$userId, $trailId, $status, $assignedBy]);
    }

    public function toggleTrailStatus(int $userId, int $trailId): string {
        $stmt = $this->db->prepare(
            'SELECT status FROM user_trails WHERE user_id = ? AND trail_id = ?'
        );
        $stmt->execute([$userId, $trailId]);
        $current   = $stmt->fetchColumn();
        $newStatus = ($current === 'unlocked') ? 'locked' : 'unlocked';
        $this->db->prepare(
            'UPDATE user_trails SET status = ? WHERE user_id = ? AND trail_id = ?'
        )->execute([$newStatus, $userId, $trailId]);
        return $newStatus;
    }

    public function removeUserTrail(int $userId, int $trailId): void {
        $this->db->prepare(
            'DELETE FROM user_trails WHERE user_id = ? AND trail_id = ?'
        )->execute([$userId, $trailId]);
    }

    /** Trilhas ainda não vinculadas a este usuário */
    public function getAvailableTrailsForUser(int $userId): array {
        $stmt = $this->db->prepare(
            'SELECT * FROM trails
             WHERE id NOT IN (SELECT trail_id FROM user_trails WHERE user_id = ?)
             ORDER BY title ASC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /**
     * Verifica se o usuário pode se matricular em um curso.
     * Regra: se o curso pertence a pelo menos uma trilha E o usuário tem
     * atribuição da trilha, a matrícula só é permitida se ao menos uma
     * atribuição estiver com status 'unlocked'.
     */
    public function canEnrollInCourse(int $userId, int $courseId): bool {
        // Quais trilhas contêm este curso?
        $stmt = $this->db->prepare('SELECT trail_id FROM trail_courses WHERE course_id = ?');
        $stmt->execute([$courseId]);
        $trailIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!$trailIds) return true; // Curso fora de qualquer trilha → livre

        $in   = implode(',', array_fill(0, count($trailIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT status FROM user_trails WHERE user_id = ? AND trail_id IN ({$in})"
        );
        $stmt->execute(array_merge([$userId], $trailIds));
        $statuses = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!$statuses) return true; // Usuário não tem atribuição nenhuma → acesso livre

        return in_array('unlocked', $statuses, true);
    }

    /**
     * Retorna TODAS as trilhas para a página inicial do catálogo.
     * Cada trilha inclui somente cursos publicados e, para usuários logados,
     * o status de atribuição (unlocked / locked / null).
     * Ordem: unlocked primeiro, depois não-atribuído, depois locked.
     */
    public function getAllTrailsForIndex(?int $userId): array {
        $stmt = $this->db->query(
            'SELECT t.*,
                    (SELECT COUNT(*)
                     FROM trail_courses tc
                     JOIN courses c ON c.id = tc.course_id
                     WHERE tc.trail_id = t.id AND c.published = 1) AS published_course_count
             FROM trails t
             ORDER BY t.title ASC'
        );
        $trails = $stmt->fetchAll();

        foreach ($trails as &$trail) {
            $s = $this->db->prepare(
                'SELECT c.*,
                        tc.sort_order,
                        (SELECT COUNT(*) FROM lessons WHERE course_id = c.id) AS lesson_count
                 FROM trail_courses tc
                 JOIN courses c ON c.id = tc.course_id
                 WHERE tc.trail_id = ? AND c.published = 1
                 ORDER BY tc.sort_order ASC, c.title ASC'
            );
            $s->execute([$trail['id']]);
            $trail['courses'] = $s->fetchAll();

            if ($userId) {
                $s = $this->db->prepare(
                    'SELECT status FROM user_trails WHERE user_id = ? AND trail_id = ?'
                );
                $s->execute([$userId, $trail['id']]);
                $trail['user_status'] = $s->fetchColumn() ?: null;
            } else {
                $trail['user_status'] = null;
            }
        }
        unset($trail);

        if ($userId) {
            $scores = ['unlocked' => 0, 'locked' => 2];
            usort($trails, function ($a, $b) use ($scores) {
                $aScore = $scores[$a['user_status']] ?? 1;
                $bScore = $scores[$b['user_status']] ?? 1;
                return $aScore <=> $bScore;
            });
        }

        return $trails;
    }

    /** Cursos publicados que não pertencem a nenhuma trilha (para o catálogo). */
    public function getStandalonePublishedCourses(): array {
        return $this->db->query(
            'SELECT c.*,
                    (SELECT COUNT(*) FROM lessons WHERE course_id = c.id) AS lesson_count
             FROM courses c
             WHERE c.published = 1
               AND c.id NOT IN (SELECT course_id FROM trail_courses)
             ORDER BY c.created_at DESC'
        )->fetchAll();
    }

    /** Trilhas do usuário com lista de cursos e progresso (para a página de trilhas do aluno) */
    public function getUserTrailsWithProgress(int $userId): array {
        $trails = $this->getUserTrails($userId);
        foreach ($trails as &$trail) {
            $courses = $this->getCoursesByTrail($trail['id']);
            foreach ($courses as &$c) {
                $s = $this->db->prepare('SELECT COUNT(*) FROM lessons WHERE course_id = ?');
                $s->execute([$c['id']]);
                $c['lesson_count'] = (int)$s->fetchColumn();

                $s = $this->db->prepare('SELECT COUNT(*) FROM progress WHERE user_id = ? AND course_id = ?');
                $s->execute([$userId, $c['id']]);
                $c['completed_count'] = (int)$s->fetchColumn();

                $s = $this->db->prepare('SELECT 1 FROM enrollments WHERE user_id = ? AND course_id = ?');
                $s->execute([$userId, $c['id']]);
                $c['enrolled'] = (bool)$s->fetch();
            }
            unset($c);
            $trail['courses'] = $courses;
        }
        unset($trail);
        return $trails;
    }
}
