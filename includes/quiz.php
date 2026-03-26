<?php
/**
 * CloudiLMS - Modelo de Questionários
 */
class QuizModel {

    private PDO $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    // ── CRUD de Questionários ────────────────────────────────────────────────

    /** Retorna todos os questionários de um curso com contagem de questões. */
    public function getQuizzesByCourse(int $courseId): array {
        $stmt = $this->db->prepare(
            'SELECT q.*,
                    COUNT(qq.id) AS question_count,
                    CASE q.placement_type
                        WHEN "after_lesson" THEN (SELECT title FROM lessons WHERE id = q.placement_id)
                        WHEN "after_topic"  THEN (SELECT title FROM topics  WHERE id = q.placement_id)
                        ELSE NULL
                    END AS placement_title
             FROM quizzes q
             LEFT JOIN quiz_questions qq ON qq.quiz_id = q.id
             WHERE q.course_id = ?
             GROUP BY q.id
             ORDER BY q.sort_order, q.id'
        );
        $stmt->execute([$courseId]);
        return $stmt->fetchAll();
    }

    /** Retorna o questionário com todas as questões e opções. */
    public function getQuizById(int $id): ?array {
        $stmt = $this->db->prepare('SELECT * FROM quizzes WHERE id = ?');
        $stmt->execute([$id]);
        $quiz = $stmt->fetch();
        if (!$quiz) return null;
        $quiz['questions'] = $this->getQuestionsWithOptions($id);
        return $quiz;
    }

    /** Retorna questões com suas opções para um questionário. */
    public function getQuestionsWithOptions(int $quizId): array {
        $stmt = $this->db->prepare(
            'SELECT * FROM quiz_questions WHERE quiz_id = ? ORDER BY sort_order, id'
        );
        $stmt->execute([$quizId]);
        $questions = $stmt->fetchAll();
        foreach ($questions as &$q) {
            $stmt2 = $this->db->prepare(
                'SELECT * FROM quiz_options WHERE question_id = ? ORDER BY sort_order, id'
            );
            $stmt2->execute([$q['id']]);
            $q['options'] = $stmt2->fetchAll();
        }
        unset($q);
        return $questions;
    }

    /** Cria um novo questionário. Retorna o ID criado. */
    public function createQuiz(array $data): int {
        $stmt = $this->db->prepare(
            'INSERT INTO quizzes
             (course_id, title, description, placement_type, placement_id, block_next,
              scoring_method, min_score, workload_minutes, sort_order, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())'
        );
        $stmt->execute([
            (int)   $data['course_id'],
                    trim($data['title']),
                    trim($data['description'] ?? '') ?: null,
                    $data['placement_type'],
                    $data['placement_id'] ? (int)$data['placement_id'] : null,
            (int)   ($data['block_next'] ?? 0),
                    $data['scoring_method'],
            (float) $data['min_score'],
            (int)   ($data['workload_minutes'] ?? 0),
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** Atualiza um questionário existente. */
    public function updateQuiz(int $id, array $data): void {
        $this->db->prepare(
            'UPDATE quizzes
             SET title=?, description=?, placement_type=?, placement_id=?, block_next=?,
                 scoring_method=?, min_score=?, workload_minutes=?
             WHERE id=?'
        )->execute([
                    trim($data['title']),
                    trim($data['description'] ?? '') ?: null,
                    $data['placement_type'],
                    $data['placement_id'] ? (int)$data['placement_id'] : null,
            (int)   ($data['block_next'] ?? 0),
                    $data['scoring_method'],
            (float) $data['min_score'],
            (int)   ($data['workload_minutes'] ?? 0),
            $id,
        ]);
    }

    /** Exclui um questionário (cascade para questões e opções). */
    public function deleteQuiz(int $id): void {
        $this->db->prepare('DELETE FROM quizzes WHERE id = ?')->execute([$id]);
    }

    // ── CRUD de Questões ─────────────────────────────────────────────────────

    /** Retorna uma questão com suas opções. */
    public function getQuestionById(int $id): ?array {
        $stmt = $this->db->prepare('SELECT * FROM quiz_questions WHERE id = ?');
        $stmt->execute([$id]);
        $q = $stmt->fetch();
        if (!$q) return null;
        $stmt2 = $this->db->prepare(
            'SELECT * FROM quiz_options WHERE question_id = ? ORDER BY sort_order, id'
        );
        $stmt2->execute([$id]);
        $q['options'] = $stmt2->fetchAll();
        return $q;
    }

    /** Cria uma nova questão. Retorna o ID criado. */
    public function createQuestion(int $quizId, string $text, float $weight): int {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM quiz_questions WHERE quiz_id = ?'
        );
        $stmt->execute([$quizId]);
        $sortOrder = (int) $stmt->fetchColumn();
        $this->db->prepare(
            'INSERT INTO quiz_questions (quiz_id, question_text, weight, sort_order)
             VALUES (?, ?, ?, ?)'
        )->execute([$quizId, trim($text), $weight, $sortOrder]);
        return (int) $this->db->lastInsertId();
    }

    /** Atualiza o texto e peso de uma questão. */
    public function updateQuestion(int $id, string $text, float $weight): void {
        $this->db->prepare(
            'UPDATE quiz_questions SET question_text=?, weight=? WHERE id=?'
        )->execute([trim($text), $weight, $id]);
    }

    /** Exclui uma questão (cascade para opções). */
    public function deleteQuestion(int $id): void {
        $this->db->prepare('DELETE FROM quiz_questions WHERE id = ?')->execute([$id]);
    }

    /**
     * Substitui todas as opções de uma questão.
     * $options = [ ['text' => '...', 'is_correct' => true|false], ... ]
     */
    public function saveOptions(int $questionId, array $options): void {
        $this->db->prepare(
            'DELETE FROM quiz_options WHERE question_id = ?'
        )->execute([$questionId]);
        $stmt = $this->db->prepare(
            'INSERT INTO quiz_options (question_id, option_text, is_correct, sort_order)
             VALUES (?, ?, ?, ?)'
        );
        foreach ($options as $i => $opt) {
            $text = trim($opt['text'] ?? '');
            if ($text === '') continue;
            $stmt->execute([$questionId, $text, $opt['is_correct'] ? 1 : 0, $i]);
        }
    }

    // ── Tentativas (Aluno) ───────────────────────────────────────────────────

    /** Inicia uma nova tentativa. Retorna o ID da tentativa. */
    public function startAttempt(int $quizId, int $userId, int $courseId): int {
        $this->db->prepare(
            'INSERT INTO quiz_attempts (quiz_id, user_id, course_id, score, passed, started_at)
             VALUES (?, ?, ?, 0, 0, NOW())'
        )->execute([$quizId, $userId, $courseId]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Submete respostas, calcula nota e finaliza tentativa.
     * $answers = [ questionId => optionId, ... ]
     * Retorna ['score'=>float, 'passed'=>bool, 'min_score'=>float, 'attempt_id'=>int]
     *      ou ['error'=>'invalid_attempt'|'no_questions']
     */
    public function submitAttempt(int $attemptId, int $userId, array $answers): array {
        $stmt = $this->db->prepare(
            'SELECT * FROM quiz_attempts WHERE id = ? AND user_id = ? AND submitted_at IS NULL'
        );
        $stmt->execute([$attemptId, $userId]);
        $attempt = $stmt->fetch();
        if (!$attempt) return ['error' => 'invalid_attempt'];

        $quiz = $this->getQuizById((int)$attempt['quiz_id']);
        if (!$quiz || empty($quiz['questions'])) return ['error' => 'no_questions'];

        $score  = $this->calculateScore($quiz['questions'], $answers, $quiz['scoring_method']);
        $passed = $score >= (float)$quiz['min_score'] ? 1 : 0;

        // Salva respostas
        $stmtAns = $this->db->prepare(
            'INSERT INTO quiz_answers (attempt_id, question_id, option_id) VALUES (?, ?, ?)'
        );
        foreach ($quiz['questions'] as $q) {
            $chosenOption = isset($answers[$q['id']]) ? (int)$answers[$q['id']] : null;
            $stmtAns->execute([$attemptId, $q['id'], $chosenOption ?: null]);
        }

        // Finaliza tentativa
        $this->db->prepare(
            'UPDATE quiz_attempts SET score=?, passed=?, submitted_at=NOW() WHERE id=?'
        )->execute([$score, $passed, $attemptId]);

        return [
            'score'      => $score,
            'passed'     => (bool)$passed,
            'min_score'  => (float)$quiz['min_score'],
            'attempt_id' => $attemptId,
        ];
    }

    /**
     * Calcula a nota (0-100) para um conjunto de respostas.
     * $questions = array com campo 'options' e 'weight'
     * $method    = 'arithmetic' | 'weighted'
     */
    private function calculateScore(array $questions, array $answers, string $method): float {
        if (empty($questions)) return 0.0;
        $totalWeight  = 0.0;
        $earnedWeight = 0.0;
        foreach ($questions as $q) {
            $w = $method === 'weighted' ? (float)($q['weight'] ?? 1.0) : 1.0;
            $totalWeight += $w;
            $chosenId = (int)($answers[$q['id']] ?? 0);
            if (!$chosenId) continue;
            foreach ($q['options'] as $opt) {
                if ((int)$opt['id'] === $chosenId && (int)$opt['is_correct']) {
                    $earnedWeight += $w;
                    break;
                }
            }
        }
        if ($totalWeight <= 0.0) return 0.0;
        return round($earnedWeight / $totalWeight * 100, 2);
    }

    /** Retorna a melhor tentativa (aprovada) ou a última tentativa finalizada. */
    public function getBestAttempt(int $quizId, int $userId): ?array {
        $stmt = $this->db->prepare(
            'SELECT * FROM quiz_attempts
             WHERE quiz_id = ? AND user_id = ? AND submitted_at IS NOT NULL
             ORDER BY passed DESC, score DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute([$quizId, $userId]);
        return $stmt->fetch() ?: null;
    }

    /** Retorna todas as tentativas finalizadas de um usuário em um questionário. */
    public function getAttemptsByUser(int $quizId, int $userId): array {
        $stmt = $this->db->prepare(
            'SELECT * FROM quiz_attempts
             WHERE quiz_id = ? AND user_id = ? AND submitted_at IS NOT NULL
             ORDER BY id DESC'
        );
        $stmt->execute([$quizId, $userId]);
        return $stmt->fetchAll();
    }

    /**
     * Retorna true se o usuário tem tentativa aprovada em TODOS os questionários
     * do curso. Se não houver questionários, retorna true para não bloquear.
     */
    public function allQuizzesPassed(int $userId, int $courseId): bool {
        $stmt = $this->db->prepare(
            'SELECT id FROM quizzes WHERE course_id = ?'
        );
        $stmt->execute([$courseId]);
        $quizIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($quizIds)) return true;
        foreach ($quizIds as $quizId) {
            $best = $this->getBestAttempt((int)$quizId, $userId);
            if (!$best || !(int)$best['passed']) return false;
        }
        return true;
    }

    /**
     * Retorna o primeiro questionário do tipo after_lesson para uma aula
     * que o usuário ainda não passou. Null se não houver.
     */
    public function getPendingQuizAfterLesson(int $lessonId, int $userId, int $courseId): ?array {
        $stmt = $this->db->prepare(
            "SELECT * FROM quizzes
             WHERE course_id = ? AND placement_type = 'after_lesson' AND placement_id = ?
             ORDER BY sort_order, id"
        );
        $stmt->execute([$courseId, $lessonId]);
        $quizzes = $stmt->fetchAll();
        foreach ($quizzes as $quiz) {
            $best = $this->getBestAttempt((int)$quiz['id'], $userId);
            if (!$best || !(int)$best['passed']) return $quiz;
        }
        return null;
    }

    /**
     * Retorna o primeiro questionário do tipo after_topic para um tópico
     * que o usuário ainda não passou.
     */
    public function getPendingQuizAfterTopic(int $topicId, int $userId, int $courseId): ?array {
        if (!$topicId) return null;
        $stmt = $this->db->prepare(
            "SELECT * FROM quizzes
             WHERE course_id = ? AND placement_type = 'after_topic' AND placement_id = ?
             ORDER BY sort_order, id"
        );
        $stmt->execute([$courseId, $topicId]);
        $quizzes = $stmt->fetchAll();
        foreach ($quizzes as $quiz) {
            $best = $this->getBestAttempt((int)$quiz['id'], $userId);
            if (!$best || !(int)$best['passed']) return $quiz;
        }
        return null;
    }

    /**
     * Retorna o primeiro questionário end_of_course do curso
     * que o usuário ainda não passou.
     */
    public function getPendingEndOfCourseQuiz(int $courseId, int $userId): ?array {
        $stmt = $this->db->prepare(
            "SELECT * FROM quizzes
             WHERE course_id = ? AND placement_type = 'end_of_course'
             ORDER BY sort_order, id"
        );
        $stmt->execute([$courseId]);
        $quizzes = $stmt->fetchAll();
        foreach ($quizzes as $quiz) {
            $best = $this->getBestAttempt((int)$quiz['id'], $userId);
            if (!$best || !(int)$best['passed']) return $quiz;
        }
        return null;
    }

    /**
     * Retorna todos os questionários do curso que o usuário ainda não passou,
     * ordenados por tipo e sort_order.
     */
    public function getPendingQuizzesByCourse(int $courseId, int $userId): array {
        $stmt = $this->db->prepare(
            'SELECT * FROM quizzes WHERE course_id = ? ORDER BY sort_order, id'
        );
        $stmt->execute([$courseId]);
        $quizzes = $stmt->fetchAll();
        $pending = [];
        foreach ($quizzes as $quiz) {
            $best = $this->getBestAttempt((int)$quiz['id'], $userId);
            if (!$best || !(int)$best['passed']) $pending[] = $quiz;
        }
        return $pending;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Verifica se o quiz pertence a um curso (segurança). */
    public function belongsToCourse(int $quizId, int $courseId): bool {
        $stmt = $this->db->prepare(
            'SELECT id FROM quizzes WHERE id = ? AND course_id = ?'
        );
        $stmt->execute([$quizId, $courseId]);
        return (bool)$stmt->fetch();
    }

    /** Verifica se o quiz pertence a um questionário (segurança). */
    public function questionBelongsToQuiz(int $questionId, int $quizId): bool {
        $stmt = $this->db->prepare(
            'SELECT id FROM quiz_questions WHERE id = ? AND quiz_id = ?'
        );
        $stmt->execute([$questionId, $quizId]);
        return (bool)$stmt->fetch();
    }

    /** Retorna o topic_id da aula (0 se não tiver). */
    public function getLessonTopicId(int $lessonId): int {
        $stmt = $this->db->prepare('SELECT topic_id FROM lessons WHERE id = ?');
        $stmt->execute([$lessonId]);
        $row = $stmt->fetch();
        return $row ? (int)($row['topic_id'] ?? 0) : 0;
    }

    /** Verifica se todas as aulas de um tópico foram concluídas pelo aluno. */
    public function isTopicComplete(int $topicId, int $userId, int $courseId): bool {
        if (!$topicId) return false;
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM lessons WHERE topic_id = ? AND course_id = ?'
        );
        $stmt->execute([$topicId, $courseId]);
        $total = (int)$stmt->fetchColumn();
        if ($total === 0) return false;
        $stmt2 = $this->db->prepare(
            'SELECT COUNT(*) FROM progress p
             JOIN lessons l ON l.id = p.lesson_id
             WHERE l.topic_id = ? AND p.user_id = ? AND p.course_id = ?'
        );
        $stmt2->execute([$topicId, $userId, $courseId]);
        return (int)$stmt2->fetchColumn() >= $total;
    }

    /** Rótulos legíveis para placement_type. */
    public static function placementLabel(string $type): string {
        return match($type) {
            'after_lesson' => 'Após aula',
            'after_topic'  => 'Após tópico',
            'end_of_course' => 'Final do curso',
            default        => $type,
        };
    }
}
