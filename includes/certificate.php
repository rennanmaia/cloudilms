<?php
/**
 * CloudiLMS - Modelo de Certificados
 */
class CertificateModel {

    private PDO $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    /**
     * Emite um certificado para o aluno (idempotente: retorna o já existente).
     * Registra o evento no log de atividades.
     */
    public function issue(int $userId, int $courseId, array $settings, array $user, array $course): array {
        $existing = $this->getByUserCourse($userId, $courseId);
        if ($existing) return $existing;

        $issuer      = trim($settings['cert_issuer'] ?? 'CloudiLMS') ?: 'CloudiLMS';
        $code        = bin2hex(random_bytes(20));   // 40 hex chars — 160 bits entropy
        $workload    = $this->calculateWorkload($courseId, $course);

        $this->db->prepare(
            'INSERT INTO certificates
             (user_id, course_id, cert_code, issued_at, workload_minutes,
              snapshot_student_name, snapshot_course_title, snapshot_issuer)
             VALUES (?,?,?,NOW(),?,?,?,?)'
        )->execute([$userId, $courseId, $code, $workload,
                    $user['name'], $course['title'], $issuer]);

        ActivityLog::record('certificate_issued', [
            'entity_type'  => 'course',
            'entity_id'    => $courseId,
            'entity_title' => $course['title'],
            'meta'         => ['cert_code' => $code],
        ]);

        return $this->getByCode($code);
    }

    /** Busca certificado pelo código de verificação (sanitiza o input). */
    public function getByCode(string $code): ?array {
        $clean = preg_replace('/[^a-f0-9]/i', '', $code);
        if (strlen($clean) < 8) return null;
        $stmt = $this->db->prepare('SELECT * FROM certificates WHERE cert_code = ?');
        $stmt->execute([$clean]);
        return $stmt->fetch() ?: null;
    }

    /** Busca certificado de um aluno em um curso específico. */
    public function getByUserCourse(int $userId, int $courseId): ?array {
        $stmt = $this->db->prepare(
            'SELECT * FROM certificates WHERE user_id = ? AND course_id = ?'
        );
        $stmt->execute([$userId, $courseId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Carga horária total em minutos:
     * soma dos duration_seconds das aulas + extra_hours_minutes do curso.
     */
    public function calculateWorkload(int $courseId, array $course): int {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(duration_seconds), 0) FROM lessons WHERE course_id = ?'
        );
        $stmt->execute([$courseId]);
        $videoMins = (int) ceil((int) $stmt->fetchColumn() / 60);
        $extraMins = (int) ($course['extra_hours_minutes'] ?? 0);
        return $videoMins + $extraMins;
    }

    /** Formata minutos para exibição humana: "2h30min", "45 minutos", etc. */
    public static function formatWorkload(int $minutes): string {
        if ($minutes <= 0)  return '—';
        if ($minutes < 60)  return $minutes . ' minuto' . ($minutes !== 1 ? 's' : '');
        $h = (int) floor($minutes / 60);
        $m = $minutes % 60;
        $label = $h . ' hora' . ($h > 1 ? 's' : '');
        if ($m > 0) $label .= ' e ' . $m . ' minuto' . ($m > 1 ? 's' : '');
        return $label;
    }
}
