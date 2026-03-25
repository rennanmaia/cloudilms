<?php
/**
 * CloudiLMS - Modelo de Cursos
 */
require_once __DIR__ . '/database.php';

class CourseModel {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    // ── Cursos ──────────────────────────────────────────────────────────────

    public function getAllCourses(bool $publishedOnly = false): array {
        $where = $publishedOnly ? 'WHERE c.published = 1' : '';
        $stmt = $this->db->query(
            "SELECT c.*, 
                    (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS lesson_count,
                    (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS student_count
             FROM courses c {$where} ORDER BY c.created_at DESC"
        );
        return $stmt->fetchAll();
    }

    public function getCourseById(int $id): ?array {
        $stmt = $this->db->prepare('SELECT * FROM courses WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function getCourseBySlug(string $slug): ?array {
        $stmt = $this->db->prepare('SELECT * FROM courses WHERE slug = ? AND published = 1');
        $stmt->execute([$slug]);
        return $stmt->fetch() ?: null;
    }

    public function createCourse(array $data): int {
        $stmt = $this->db->prepare(
            'INSERT INTO courses (title, slug, description, thumbnail, gdrive_folder_id, gdrive_folder_url, published, created_at)
             VALUES (:title, :slug, :description, :thumbnail, :gdrive_folder_id, :gdrive_folder_url, :published, NOW())'
        );
        $stmt->execute([
            ':title'             => $data['title'],
            ':slug'              => $this->makeSlug($data['title']),
            ':description'       => $data['description'] ?? '',
            ':thumbnail'         => $data['thumbnail'] ?? '',
            ':gdrive_folder_id'  => $data['gdrive_folder_id'],
            ':gdrive_folder_url' => $data['gdrive_folder_url'],
            ':published'         => (int)($data['published'] ?? 0),
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function updateCourse(int $id, array $data): bool {
        $stmt = $this->db->prepare(
            'UPDATE courses SET title=:title, description=:description, thumbnail=:thumbnail,
             gdrive_folder_id=:gdrive_folder_id, gdrive_folder_url=:gdrive_folder_url,
             published=:published WHERE id=:id'
        );
        return $stmt->execute([
            ':title'             => $data['title'],
            ':description'       => $data['description'] ?? '',
            ':thumbnail'         => $data['thumbnail'] ?? '',
            ':gdrive_folder_id'  => $data['gdrive_folder_id'],
            ':gdrive_folder_url' => $data['gdrive_folder_url'],
            ':published'         => (int)($data['published'] ?? 0),
            ':id'                => $id,
        ]);
    }

    public function deleteCourse(int $id): bool {
        $this->db->prepare('DELETE FROM lessons WHERE course_id = ?')->execute([$id]);
        $this->db->prepare('DELETE FROM enrollments WHERE course_id = ?')->execute([$id]);
        $this->db->prepare('DELETE FROM progress WHERE course_id = ?')->execute([$id]);
        return $this->db->prepare('DELETE FROM courses WHERE id = ?')->execute([$id]);
    }

    // ── Aulas ───────────────────────────────────────────────────────────────

    public function getLessonsByCourse(int $courseId): array {
        $stmt = $this->db->prepare(
            'SELECT * FROM lessons WHERE course_id = ? ORDER BY sort_order ASC, title ASC'
        );
        $stmt->execute([$courseId]);
        return $stmt->fetchAll();
    }

    public function getLessonById(int $id): ?array {
        $stmt = $this->db->prepare('SELECT * FROM lessons WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function syncLessons(int $courseId, array $driveFiles): int {
        // Busca aulas já existentes para não duplicar
        $stmt = $this->db->prepare('SELECT gdrive_file_id FROM lessons WHERE course_id = ?');
        $stmt->execute([$courseId]);
        $existing = array_column($stmt->fetchAll(), 'gdrive_file_id');

        $added = 0;
        $order = count($existing) + 1;

        foreach ($driveFiles as $file) {
            if (in_array($file['id'], $existing)) continue;
            $mime = $file['mimeType'] ?? '';
            if ($mime === 'application/vnd.google-apps.folder') continue;

            $duration = null;
            if (!empty($file['videoMediaMetadata']['durationMillis'])) {
                $duration = (int)round($file['videoMediaMetadata']['durationMillis'] / 1000);
            }

            $stmt2 = $this->db->prepare(
                'INSERT INTO lessons (course_id, title, gdrive_file_id, mime_type, duration_seconds, sort_order, created_at)
                 VALUES (:course_id, :title, :gdrive_file_id, :mime_type, :duration_seconds, :sort_order, NOW())'
            );
            $stmt2->execute([
                ':course_id'        => $courseId,
                ':title'            => $this->cleanTitle($file['name']),
                ':gdrive_file_id'   => $file['id'],
                ':mime_type'        => $mime,
                ':duration_seconds' => $duration,
                ':sort_order'       => $order++,
            ]);
            $added++;
        }
        return $added;
    }

    public function updateLessonOrder(int $lessonId, int $order): void {
        $this->db->prepare('UPDATE lessons SET sort_order = ? WHERE id = ?')->execute([$order, $lessonId]);
    }

    public function deleteLesson(int $id): void {
        $this->db->prepare('DELETE FROM lessons WHERE id = ?')->execute([$id]);
    }

    // ── Matrículas ──────────────────────────────────────────────────────────

    public function isEnrolled(int $userId, int $courseId): bool {
        $stmt = $this->db->prepare('SELECT 1 FROM enrollments WHERE user_id = ? AND course_id = ?');
        $stmt->execute([$userId, $courseId]);
        return (bool)$stmt->fetch();
    }

    public function enroll(int $userId, int $courseId): void {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO enrollments (user_id, course_id, enrolled_at) VALUES (?, ?, NOW())'
        );
        $stmt->execute([$userId, $courseId]);
    }

    public function getEnrolledCourses(int $userId): array {
        $stmt = $this->db->prepare(
            'SELECT c.*, e.enrolled_at,
                    (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS lesson_count,
                    (SELECT COUNT(*) FROM progress p WHERE p.user_id = ? AND p.course_id = c.id AND p.completed = 1) AS completed_count
             FROM courses c JOIN enrollments e ON c.id = e.course_id
             WHERE e.user_id = ? ORDER BY e.enrolled_at DESC'
        );
        $stmt->execute([$userId, $userId]);
        return $stmt->fetchAll();
    }

    // ── Progresso ───────────────────────────────────────────────────────────

    public function markComplete(int $userId, int $courseId, int $lessonId): void {
        $stmt = $this->db->prepare(
            'INSERT INTO progress (user_id, course_id, lesson_id, completed, completed_at)
             VALUES (?, ?, ?, 1, NOW())
             ON DUPLICATE KEY UPDATE completed = 1, completed_at = NOW()'
        );
        $stmt->execute([$userId, $courseId, $lessonId]);
    }

    public function getProgress(int $userId, int $courseId): array {
        $stmt = $this->db->prepare(
            'SELECT lesson_id FROM progress WHERE user_id = ? AND course_id = ? AND completed = 1'
        );
        $stmt->execute([$userId, $courseId]);
        return array_column($stmt->fetchAll(), 'lesson_id');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function makeSlug(string $title): string {
        $slug = strtolower(trim($title));
        $slug = iconv('UTF-8', 'ASCII//TRANSLIT', $slug);
        $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
        // garante unicidade
        $base = $slug;
        $i = 1;
        while (true) {
            $stmt = $this->db->prepare('SELECT 1 FROM courses WHERE slug = ?');
            $stmt->execute([$slug]);
            if (!$stmt->fetch()) break;
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }

    private function cleanTitle(string $name): string {
        // Remove extensão do arquivo
        $name = preg_replace('/\.(mp4|mkv|avi|mov|webm|flv|wmv|m4v)$/i', '', $name);
        // Remove numeração inicial tipo "01 - " ou "01. "
        $name = preg_replace('/^\d+[\s\.\-_]+/', '', $name);
        return trim($name);
    }
}
