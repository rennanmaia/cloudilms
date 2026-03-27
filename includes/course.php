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
             published=:published, extra_hours_minutes=:extra_hours_minutes WHERE id=:id'
        );
        return $stmt->execute([
            ':title'               => $data['title'],
            ':description'         => $data['description'] ?? '',
            ':thumbnail'           => $data['thumbnail'] ?? '',
            ':gdrive_folder_id'    => $data['gdrive_folder_id'],
            ':gdrive_folder_url'   => $data['gdrive_folder_url'],
            ':published'           => (int)($data['published'] ?? 0),
            ':extra_hours_minutes' => (int)($data['extra_hours_minutes'] ?? 0),
            ':id'                  => $id,
        ]);
    }

    public function deleteCourse(int $id): bool {
        $this->db->prepare('DELETE FROM lessons WHERE course_id = ?')->execute([$id]);
        $this->db->prepare('DELETE FROM topics WHERE course_id = ?')->execute([$id]);
        $this->db->prepare('DELETE FROM enrollments WHERE course_id = ?')->execute([$id]);
        $this->db->prepare('DELETE FROM progress WHERE course_id = ?')->execute([$id]);
        return $this->db->prepare('DELETE FROM courses WHERE id = ?')->execute([$id]);
    }

    // ── Tópicos ──────────────────────────────────────────────────────────────

    public function getTopicsByCourse(int $courseId): array {
        $stmt = $this->db->prepare(
            'SELECT * FROM topics WHERE course_id = ? ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$courseId]);
        return $stmt->fetchAll();
    }

    public function createTopic(int $courseId, string $title, int $order = 0): int {
        $this->db->prepare('INSERT INTO topics (course_id, title, sort_order) VALUES (?, ?, ?)')
            ->execute([$courseId, $title, $order]);
        return (int)$this->db->lastInsertId();
    }

    public function updateTopic(int $id, string $title): void {
        $this->db->prepare('UPDATE topics SET title = ? WHERE id = ?')->execute([$title, $id]);
    }

    public function updateTopicOrder(int $id, int $order): void {
        $this->db->prepare('UPDATE topics SET sort_order = ? WHERE id = ?')->execute([$order, $id]);
    }

    public function deleteTopic(int $topicId): void {
        $this->db->prepare('UPDATE lessons SET topic_id = NULL WHERE topic_id = ?')->execute([$topicId]);
        $this->db->prepare('DELETE FROM topics WHERE id = ?')->execute([$topicId]);
    }

    public function assignLessonToTopic(int $lessonId, ?int $topicId): void {
        $this->db->prepare('UPDATE lessons SET topic_id = ? WHERE id = ?')->execute([$topicId, $lessonId]);
    }

    /**
     * Retorna aulas agrupadas por tópico.
     * Cada grupo: ['topic' => array|null, 'lessons' => array]
     */
    public function getLessonsGroupedByTopic(int $courseId): array {
        $topics  = $this->getTopicsByCourse($courseId);
        $lessons = $this->getLessonsByCourse($courseId);

        if (empty($topics)) {
            return [['topic' => null, 'lessons' => $lessons]];
        }

        $byTopic = [];
        foreach ($lessons as $l) {
            $key = $l['topic_id'] !== null ? (int)$l['topic_id'] : 0;
            $byTopic[$key][] = $l;
        }

        $groups = [];
        foreach ($topics as $t) {
            $groups[] = [
                'topic'   => $t,
                'lessons' => $byTopic[(int)$t['id']] ?? [],
            ];
        }
        if (!empty($byTopic[0])) {
            $groups[] = [
                'topic'   => ['id' => null, 'title' => 'Sem tópico'],
                'lessons' => $byTopic[0],
            ];
        }
        return $groups;
    }

    // ── Aulas ───────────────────────────────────────────────────────────────

    public function getLessonsByCourse(int $courseId): array {
        $stmt = $this->db->prepare(
            'SELECT l.* FROM lessons l
             LEFT JOIN topics t ON l.topic_id = t.id
             WHERE l.course_id = ?
             ORDER BY COALESCE(t.sort_order, 9999) ASC, l.sort_order ASC, l.title ASC'
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
        $added = 0;
        foreach ($driveFiles as $file) {
            $added += $this->insertLesson($courseId, null, $file);
        }
        return $added;
    }

    /**
     * Sincroniza aulas detectando subpastas como tópicos.
     * $rootVideos   = vídeos na raiz da pasta principal
     * $subfolderData = ['Nome da Pasta' => [array de arquivos Drive], ...]
     */
    public function syncLessonsWithTopics(int $courseId, array $rootVideos, array $subfolderData): int {
        $added = 0;

        // Vídeos na raiz → sem tópico
        foreach ($rootVideos as $file) {
            $added += $this->insertLesson($courseId, null, $file);
        }

        // Tópicos existentes indexados por nome
        $existingTopics = $this->getTopicsByCourse($courseId);
        $topicsByName   = [];
        foreach ($existingTopics as $t) {
            $topicsByName[mb_strtolower($t['title'])] = (int)$t['id'];
        }
        $topicOrder = count($existingTopics);

        foreach ($subfolderData as $folderName => $files) {
            $nameKey = mb_strtolower($folderName);
            if (!isset($topicsByName[$nameKey])) {
                $topicId = $this->createTopic($courseId, $folderName, ++$topicOrder);
                $topicsByName[$nameKey] = $topicId;
            } else {
                $topicId = $topicsByName[$nameKey];
            }
            foreach ((array)$files as $file) {
                $added += $this->insertLesson($courseId, $topicId, $file);
            }
        }

        return $added;
    }

    /** Insere uma única aula (sem duplicar). Retorna 1 se inserida, 0 se já existia. */
    private function insertLesson(int $courseId, ?int $topicId, array $file): int {
        $mime = $file['mimeType'] ?? '';
        if (!str_starts_with($mime, 'video/')) return 0;

        $check = $this->db->prepare('SELECT 1 FROM lessons WHERE course_id = ? AND gdrive_file_id = ?');
        $check->execute([$courseId, $file['id']]);
        if ($check->fetch()) return 0;

        $duration = null;
        if (!empty($file['videoMediaMetadata']['durationMillis'])) {
            $duration = (int)round($file['videoMediaMetadata']['durationMillis'] / 1000);
        }

        // sort_order dentro do mesmo tópico
        if ($topicId !== null) {
            $stmtMax = $this->db->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM lessons WHERE course_id = ? AND topic_id = ?');
            $stmtMax->execute([$courseId, $topicId]);
        } else {
            $stmtMax = $this->db->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM lessons WHERE course_id = ? AND topic_id IS NULL');
            $stmtMax->execute([$courseId]);
        }
        $maxOrder = (int)$stmtMax->fetchColumn();

        $this->db->prepare(
            'INSERT INTO lessons (course_id, topic_id, title, gdrive_file_id, mime_type, duration_seconds, sort_order, created_at)
             VALUES (:course_id, :topic_id, :title, :gdrive_file_id, :mime_type, :duration_seconds, :sort_order, NOW())'
        )->execute([
            ':course_id'        => $courseId,
            ':topic_id'         => $topicId,
            ':title'            => $this->cleanTitle($file['name']),
            ':gdrive_file_id'   => $file['id'],
            ':mime_type'        => $mime,
            ':duration_seconds' => $duration,
            ':sort_order'       => $maxOrder + 1,
        ]);
        return 1;
    }

    public function updateLessonOrder(int $lessonId, int $order): void {
        $this->db->prepare('UPDATE lessons SET sort_order = ? WHERE id = ?')->execute([$order, $lessonId]);
    }

    public function updateLessonSettings(int $lessonId, int $preventSeek, int $forceSequential, int $requireWatch = 1): void {
        $this->db->prepare(
            'UPDATE lessons SET prevent_seek = ?, force_sequential = ?, require_watch = ? WHERE id = ?'
        )->execute([$preventSeek, $forceSequential, $requireWatch, $lessonId]);
    }

    public function updateLessonEstimated(int $lessonId, ?int $estimatedSeconds): void {
        $this->db->prepare('UPDATE lessons SET estimated_seconds = ? WHERE id = ?')
                 ->execute([$estimatedSeconds, $lessonId]);
    }

    public function deleteLesson(int $id): void {
        // Remove arquivos de disco antes do CASCADE apagar os registros
        $atts = $this->getAttachmentsByLesson($id);
        foreach ($atts as $att) {
            if (!empty($att['file_path'])) {
                $filePath = __DIR__ . '/../uploads/attachments/' . basename($att['file_path']);
                if (file_exists($filePath) && is_file($filePath)) @unlink($filePath);
            }
        }
        $this->db->prepare('DELETE FROM lessons WHERE id = ?')->execute([$id]);
    }

    /**
     * Cria uma aula avulsa (não sincronizada pelo Drive).
     * @param ?string $gdriveFileId  ID do vídeo no Drive, ou null se não há vídeo.
     */
    public function createManualLesson(int $courseId, ?int $topicId, string $title, ?string $gdriveFileId, ?string $mimeType, ?string $bodyText): int {
        if ($topicId !== null) {
            $stmtMax = $this->db->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM lessons WHERE course_id = ? AND topic_id = ?');
            $stmtMax->execute([$courseId, $topicId]);
        } else {
            $stmtMax = $this->db->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM lessons WHERE course_id = ? AND topic_id IS NULL');
            $stmtMax->execute([$courseId]);
        }
        $maxOrder = (int)$stmtMax->fetchColumn();

        $this->db->prepare(
            'INSERT INTO lessons (course_id, topic_id, title, gdrive_file_id, mime_type, sort_order, body_text, created_at)
             VALUES (:course_id, :topic_id, :title, :gdrive_file_id, :mime_type, :sort_order, :body_text, NOW())'
        )->execute([
            ':course_id'      => $courseId,
            ':topic_id'       => $topicId,
            ':title'          => $title,
            ':gdrive_file_id' => $gdriveFileId ?: null,
            ':mime_type'      => $mimeType ?: null,
            ':sort_order'     => $maxOrder + 1,
            ':body_text'      => $bodyText ?: null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Atualiza os campos editáveis de uma aula (avulsa ou sincronizada).
     */
    public function updateLesson(int $id, string $title, ?string $gdriveFileId, ?string $mimeType, ?string $bodyText): void {
        $this->db->prepare(
            'UPDATE lessons SET title = :title, gdrive_file_id = :gdrive_file_id, mime_type = :mime_type, body_text = :body_text WHERE id = :id'
        )->execute([
            ':title'          => $title,
            ':gdrive_file_id' => $gdriveFileId ?: null,
            ':mime_type'      => $mimeType ?: null,
            ':body_text'      => $bodyText ?: null,
            ':id'             => $id,
        ]);
    }

    // ── Anexos de aulas ─────────────────────────────────────────────────────

    public function getAttachmentsByLesson(int $lessonId): array {
        $stmt = $this->db->prepare(
            'SELECT * FROM lesson_attachments WHERE lesson_id = ? ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$lessonId]);
        return $stmt->fetchAll();
    }

    public function getAttachmentById(int $id): ?array {
        $stmt = $this->db->prepare('SELECT * FROM lesson_attachments WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function addAttachment(int $lessonId, string $title, ?string $gdriveFileId, ?string $mimeType, ?string $filePath = null): int {
        $stmt = $this->db->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM lesson_attachments WHERE lesson_id = ?');
        $stmt->execute([$lessonId]);
        $maxOrder = (int)$stmt->fetchColumn();

        $this->db->prepare(
            'INSERT INTO lesson_attachments (lesson_id, title, gdrive_file_id, mime_type, file_path, sort_order, created_at)
             VALUES (:lesson_id, :title, :gdrive_file_id, :mime_type, :file_path, :sort_order, NOW())'
        )->execute([
            ':lesson_id'      => $lessonId,
            ':title'          => $title,
            ':gdrive_file_id' => $gdriveFileId ?: null,
            ':mime_type'      => $mimeType ?: null,
            ':file_path'      => $filePath ?: null,
            ':sort_order'     => $maxOrder + 1,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function deleteAttachment(int $attachmentId): void {
        $att = $this->getAttachmentById($attachmentId);
        if ($att && !empty($att['file_path'])) {
            $filePath = __DIR__ . '/../uploads/attachments/' . basename($att['file_path']);
            if (file_exists($filePath) && is_file($filePath)) @unlink($filePath);
        }
        $this->db->prepare('DELETE FROM lesson_attachments WHERE id = ?')->execute([$attachmentId]);
    }

    // ── Matrículas ──────────────────────────────────────────────────────────

    /**
     * Retorna true apenas se o aluno possui matrícula ATIVA (não expirada).
     * Usar em páginas de aluno para controle de acesso.
     */
    public function isEnrolled(int $userId, int $courseId): bool {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM enrollments
             WHERE user_id = ? AND course_id = ?
               AND (expires_at IS NULL OR expires_at > NOW())'
        );
        $stmt->execute([$userId, $courseId]);
        return (bool)$stmt->fetch();
    }

    /**
     * Retorna a linha de matrícula (incluindo expiradas) ou false se não existe.
     */
    public function getEnrollment(int $userId, int $courseId): array|false {
        $stmt = $this->db->prepare(
            'SELECT * FROM enrollments WHERE user_id = ? AND course_id = ?'
        );
        $stmt->execute([$userId, $courseId]);
        return $stmt->fetch();
    }

    /**
     * Retorna true se existe qualquer matrícula (ativa ou expirada).
     * Usar no admin ao evitar duplicate-enroll.
     */
    public function hasEnrollment(int $userId, int $courseId): bool {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM enrollments WHERE user_id = ? AND course_id = ?'
        );
        $stmt->execute([$userId, $courseId]);
        return (bool)$stmt->fetch();
    }

    /**
     * Define o prazo de validade da matrícula.
     * Passa null para remover o prazo (sem expiração).
     */
    public function setExpiresAt(int $userId, int $courseId, ?string $expiresAt): void {
        $this->db->prepare(
            'UPDATE enrollments SET expires_at = ? WHERE user_id = ? AND course_id = ?'
        )->execute([$expiresAt, $userId, $courseId]);
    }

    public function enroll(int $userId, int $courseId, ?string $expiresAt = null): void {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO enrollments (user_id, course_id, enrolled_at, expires_at)
             VALUES (?, ?, NOW(), ?)'
        );
        $stmt->execute([$userId, $courseId, $expiresAt]);
    }

    /**
     * Cancela matrícula: remove enrollment, progresso e certificado.
     * Os registros de activity_log são mantidos intencionalmente.
     */
    public function cancelEnrollment(int $userId, int $courseId): void {
        $this->db->prepare('DELETE FROM enrollments WHERE user_id = ? AND course_id = ?')
                 ->execute([$userId, $courseId]);
        $this->db->prepare('DELETE FROM progress WHERE user_id = ? AND course_id = ?')
                 ->execute([$userId, $courseId]);
        $this->db->prepare('DELETE FROM certificates WHERE user_id = ? AND course_id = ?')
                 ->execute([$userId, $courseId]);
    }

    public function getEnrolledCourses(int $userId): array {
        $stmt = $this->db->prepare(
            'SELECT c.*, e.enrolled_at, e.expires_at,
                    (e.expires_at IS NOT NULL AND e.expires_at <= NOW()) AS is_expired,
                    (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS lesson_count,
                    (SELECT COUNT(*) FROM progress p WHERE p.user_id = ? AND p.course_id = c.id AND p.completed = 1) AS completed_count,
                    (SELECT cert_code FROM certificates WHERE user_id = ? AND course_id = c.id LIMIT 1) AS cert_code
             FROM courses c JOIN enrollments e ON c.id = e.course_id
             WHERE e.user_id = ? ORDER BY e.enrolled_at DESC'
        );
        $stmt->execute([$userId, $userId, $userId]);
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
