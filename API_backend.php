<?php
header('Content-Type: application/json');

// Configuração do banco de dados
$cloneDbConfig = [
    'host' => 'localhost',
    'dbname' => 'clone_moodle',
    'user' => 'root2',
    'password' => '1'
];

// Função para conectar ao banco de dados
	function connectDatabase($config) {
		try {
			$dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset=utf8";
			$pdo = new PDO($dsn, $config['user'], $config['password']);
			$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			return $pdo;
		} catch (PDOException $e) {
			die(json_encode(['error' => $e->getMessage()]));
		}
	};

$cloneDb = connectDatabase($cloneDbConfig);

// Processa a ação requisitada
$action = $_GET['action'] ?? '';

if ($action == 'autocomplete') {
    $type = $_GET['type'];
    $query = $_GET['query'];

    if ($type == 'user') {
        $stmt = $cloneDb->prepare("SELECT id, CONCAT(firstname, ' ', lastname) AS name FROM users WHERE CONCAT(firstname, ' ', lastname) LIKE :query LIMIT 10");
        $stmt->execute(['query' => "%$query%"]);
    } elseif ($type == 'course') {
        $stmt = $cloneDb->prepare("SELECT id, fullname AS name FROM courses WHERE fullname LIKE :query LIMIT 10");
        $stmt->execute(['query' => "%$query%"]);
    }

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($results);
}

if ($action == 'grades_by_user') {
    $user_name = $_GET['user_name'];
    $stmt = $cloneDb->prepare("
        SELECT c.fullname AS course_name, g.grade
        FROM course_grade_history g
        JOIN courses c ON g.course_id = c.id
        JOIN users u ON g.user_id = u.id
        WHERE CONCAT(u.firstname, ' ', u.lastname) LIKE :user_name
    ");
    $stmt->execute(['user_name' => "%$user_name%"]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($results);
}

if ($action == 'users_by_course') {
    $course_name = $_GET['course_name'];
    $stmt = $cloneDb->prepare("
        SELECT u.firstname, u.lastname, r.shortname
        FROM user_course_history uh
        JOIN users u ON uh.user_id = u.id
		JOIN roles r ON uh.role_id = r.id
        JOIN courses c ON uh.course_id = c.id
        WHERE c.fullname LIKE :course_name
		AND uh.role_id = 5
		AND uh.updated_at = (
			SELECT MAX(uh2.updated_at)
			FROM user_course_history uh2
			WHERE uh2.user_id = uh.user_id AND uh2.course_id = uh.course_id
		)
    ");
    $stmt->execute(['course_name' => "%$course_name%"]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($results);
}

if ($action == 'all_courses') {
    $stmt = $cloneDb->query("SELECT id, fullname FROM courses");
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($results);
}

if ($action == 'all_students_by_course') {
    $course_name = $_GET['course_name'];
    $stmt = $cloneDb->prepare("
        SELECT u.firstname, u.lastname
        FROM user_course_history uh
        JOIN users u ON uh.user_id = u.id
        JOIN courses c ON uh.course_id = c.id
        WHERE c.fullname LIKE :course_name
		AND uh.role_id = 5
		AND uh.updated_at = (
			SELECT MAX(uh2.updated_at)
			FROM user_course_history uh2
			WHERE uh2.user_id = uh.user_id AND uh2.course_id = uh.course_id
		)
    ");
	
    $stmt->execute(['course_name' => "%$course_name%"]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($results);
}

if ($action == 'all_grades_by_course') {
    $course_name = $_GET['course_name'];
    $stmt = $cloneDb->prepare("
        SELECT c.fullname AS course_name, g.grade
        FROM course_grade_history g
        JOIN courses c ON g.course_id = c.id
        WHERE c.fullname LIKE :course_name
    ");
    $stmt->execute(['course_name' => "%$course_name%"]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($results);
}

?>
