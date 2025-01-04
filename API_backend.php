<?php
set_time_limit(0); // Desativa o limite de execução do script para longos períodos

// Configuração do banco de dados Moodle (origem)
$moodleDbConfig = [
    'host' => 'localhost',
    'dbname' => 'moodle',
    'user' => 'root',
    'password' => ''
];

// Configuração do banco de dados Clone (destino)
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
        die("Erro ao conectar ao banco de dados: " . $e->getMessage());
    }
};

function migrateUsers($moodleDb, $cloneDb) {
    try {
        // 1. Obter informações dos usuários
        $query = $moodleDb->prepare("
            SELECT 
                u.id AS user_id, 
                u.firstname, 
                u.lastname, 
                u.email, 
                u.city
            FROM mdl_user u
            WHERE u.deleted = 0 -- Garantir que estamos pegando usuários não excluídos
        ");
        $query->execute();
        $users = $query->fetchAll(PDO::FETCH_ASSOC);

        if (empty($users)) {
            echo "Nenhum usuário encontrado para migrar.\n";
            return;
        }

        // 2. Preparar a inserção dos usuários na tabela `users` do banco Clone
        $insertUserQuery = $cloneDb->prepare("
            INSERT INTO users (id, firstname, lastname, email, city)
            VALUES (:user_id, :firstname, :lastname, :email, :city)
            ON DUPLICATE KEY UPDATE 
                firstname = VALUES(firstname),
                lastname = VALUES(lastname),
                email = VALUES(email),
                city = VALUES(city)
        ");

        foreach ($users as $user) {
            $insertUserQuery->bindValue(':user_id', $user['user_id'], PDO::PARAM_INT);
            $insertUserQuery->bindValue(':firstname', $user['firstname'], PDO::PARAM_STR);
            $insertUserQuery->bindValue(':lastname', $user['lastname'], PDO::PARAM_STR);
            $insertUserQuery->bindValue(':email', $user['email'], PDO::PARAM_STR);
            $insertUserQuery->bindValue(':city', $user['city'], PDO::PARAM_STR);

            try {
                $insertUserQuery->execute();
            } catch (PDOException $e) {
                echo "Erro ao inserir usuário ID {$user['user_id']}: " . $e->getMessage() . "\n";
            }
        }

        echo "Usuários migrados com sucesso.\n";
    } catch (PDOException $e) {
        echo "Erro durante a migração de usuários: " . $e->getMessage() . "\n";
    }
};

// Função para migrar os cursos para a tabela `courses` no banco Clone
function migrateCourses($moodleDb, $cloneDb) {
    try {
        // 1. Obter informações dos cursos, incluindo todos os campos necessários
        $query = $moodleDb->prepare("
            SELECT 
                c.id AS course_id, 
                c.fullname AS course_name,
                c.shortname,
                c.category,
                c.visible,
                c.startdate,
                c.enddate,
                c.summary
            FROM mdl_course c
        ");
        $query->execute();
        $courses = $query->fetchAll(PDO::FETCH_ASSOC);

        if (empty($courses)) {
            echo "Nenhum curso encontrado para migrar.\n";
            return;
        }

        // 2. Preparar a inserção dos cursos na tabela `courses` do banco Clone
        $insertCourseQuery = $cloneDb->prepare("
            INSERT INTO courses (
                id, fullname, shortname, category, visible, startdate, enddate, summary
            ) VALUES (
                :course_id, :course_name, :shortname, :category, :visible, :startdate, :enddate, :summary
            )
            ON DUPLICATE KEY UPDATE 
                fullname = VALUES(fullname),
                shortname = VALUES(shortname),
                category = VALUES(category),
                visible = VALUES(visible),
                startdate = VALUES(startdate),
                enddate = VALUES(enddate),
                summary = VALUES(summary)
        ");

        // 3. Inserir os dados para cada curso
        foreach ($courses as $course) {
            // Converter o campo startdate e enddate para o formato correto de datetime (se necessário)
            $startdate = ($course['startdate'] !== null) ? date('Y-m-d H:i:s', $course['startdate']) : null;
            $enddate = ($course['enddate'] !== null) ? date('Y-m-d H:i:s', $course['enddate']) : null;

            // Bind dos valores para inserção
            $insertCourseQuery->bindValue(':course_id', $course['course_id'], PDO::PARAM_INT);
            $insertCourseQuery->bindValue(':course_name', $course['course_name'], PDO::PARAM_STR);
            $insertCourseQuery->bindValue(':shortname', $course['shortname'], PDO::PARAM_STR);
            $insertCourseQuery->bindValue(':category', $course['category'], PDO::PARAM_INT);
            $insertCourseQuery->bindValue(':visible', $course['visible'], PDO::PARAM_INT);
            $insertCourseQuery->bindValue(':startdate', $startdate, PDO::PARAM_STR);
            $insertCourseQuery->bindValue(':enddate', $enddate, PDO::PARAM_STR);     
            $insertCourseQuery->bindValue(':summary', $course['summary'], PDO::PARAM_STR);

            try {
                $insertCourseQuery->execute();
            } catch (PDOException $e) {
                echo "Erro ao inserir curso ID {$course['course_id']}: " . $e->getMessage() . "\n";
            }
        }

        echo "Cursos migrados com sucesso.\n";
    } catch (PDOException $e) {
        echo "Erro durante a migração de cursos: " . $e->getMessage() . "\n";
    }
};


// Função para migrar os dados
function migrateData($moodleDb, $cloneDb) {
    try {
        // 1. Obter informações principais dos usuários e cursos
        $query = $moodleDb->prepare("
            SELECT 
                u.id AS user_id, 
                u.firstname, 
                u.lastname, 
                u.email, 
                u.city, 
                ra.roleid,
                u.lastaccess AS last_access,
                c.id AS course_id, 
                c.fullname AS course_name,
                la.timeaccess AS last_access
            FROM mdl_user u
            JOIN mdl_role_assignments ra ON u.id = ra.userid
            JOIN mdl_context ctx ON ra.contextid = ctx.id
            JOIN mdl_course c ON ctx.instanceid = c.id
            LEFT JOIN mdl_user_lastaccess la ON u.id = la.userid AND c.id = la.courseid
            WHERE ra.roleid IN (3, 5) -- Apenas professores e alunos
            GROUP BY u.id, u.firstname, u.lastname, u.email, u.city, ra.roleid, c.id, c.fullname;
        ");
        $query->execute();
        $records = $query->fetchAll(PDO::FETCH_ASSOC);

        if (empty($records)) {
            echo "Nenhum dado encontrado para migrar.\n";
            exit;
        }

        // 2. Preparar a inserção de dados
        $insertQuery = $cloneDb->prepare("
            INSERT INTO user_course_history (
                user_id, course_id, role_id, last_access
            ) VALUES (
                :user_id, :course_id, :role_id, FROM_UNIXTIME(:last_access)
            )
            ON DUPLICATE KEY UPDATE 
                last_access = GREATEST(last_access, VALUES(last_access)),
                updated_at = CURRENT_TIMESTAMP
        ");

        // 3. Verificar se o user_id existe na tabela `users` antes de inserir
        $userCheckQuery = $cloneDb->prepare("SELECT id FROM users WHERE id = :user_id");

        foreach ($records as $record) {
            // Verifica se o user_id existe na tabela `users` do banco Clone
            $userCheckQuery->bindValue(':user_id', $record['user_id'], PDO::PARAM_INT);
            $userCheckQuery->execute();

            // Se o user_id não existir, pule para o próximo registro
            if ($userCheckQuery->rowCount() == 0) {
                echo "User ID {$record['user_id']} não encontrado na tabela de destino. Pulando...\n";
                continue;
            }

            // Se o user_id existir, insira o dado na tabela de histórico
            $insertQuery->bindValue(':user_id', $record['user_id'], PDO::PARAM_INT);
            $insertQuery->bindValue(':course_id', $record['course_id'], PDO::PARAM_INT);
            $insertQuery->bindValue(':role_id', $record['roleid'], PDO::PARAM_INT);
            $insertQuery->bindValue(':last_access', $record['last_access'], PDO::PARAM_STR); // Caso DATETIME

            try {
                $insertQuery->execute();
            } catch (PDOException $e) {
                echo "Erro ao inserir registro para o user_id {$record['user_id']}: " . $e->getMessage() . "\n";
            }
        }
		
		echo "Histórico dos usuários migrados com sucesso.\n";

    } catch (PDOException $e) {
        echo "Erro durante a migração: " . $e->getMessage() . "\n";
    }
};


function migrateGradesWithHistory($moodleDb, $cloneDb) {
    try {
        // Consulta as notas no Moodle
        $gradeQuery = $moodleDb->prepare("
            SELECT 
                gi.courseid AS course_id,
                g.userid AS user_id,
                g.finalgrade AS grade
            FROM mdl_grade_grades g
            JOIN mdl_grade_items gi ON g.itemid = gi.id
            WHERE gi.itemtype = 'course'
        ");
        $gradeQuery->execute();
        $grades = $gradeQuery->fetchAll(PDO::FETCH_ASSOC);

        // Inserção ou atualização no banco Clone
        $insertGradeQuery = $cloneDb->prepare("
            INSERT INTO course_grade_history (user_id, course_id, grade, updated_at)
            VALUES (:user_id, :course_id, :grade, NOW())
            ON DUPLICATE KEY UPDATE
                grade = VALUES(grade),
                updated_at = NOW()
        ");

        $insertHistoryQuery = $cloneDb->prepare("
            INSERT INTO course_grade_history (user_id, course_id, grade, updated_at)
            VALUES (:user_id, :course_id, :grade, NOW())
        ");

        foreach ($grades as $grade) {
            // Atualiza tabela principal
            $insertGradeQuery->execute([
                ':user_id' => $grade['user_id'],
                ':course_id' => $grade['course_id'],
                ':grade' => $grade['grade']
            ]);

            // Insere histórico
            $insertHistoryQuery->execute([
                ':user_id' => $grade['user_id'],
                ':course_id' => $grade['course_id'],
                ':grade' => $grade['grade']
            ]);
        }

        echo "Notas e histórico de notas migrados com sucesso.\n";

    } catch (PDOException $e) {
        echo "Erro durante a migração de notas: " . $e->getMessage() . "\n";
    }
};

// Loop para executar a cada 24 horas
while (true) {
    echo "Iniciando migração...\n";
    $moodleDb = connectDatabase($moodleDbConfig);
    $cloneDb = connectDatabase($cloneDbConfig);

	migrateUsers($moodleDb, $cloneDb);
	migrateCourses($moodleDb, $cloneDb);
    migrateData($moodleDb, $cloneDb);
	migrateGradesWithHistory($moodleDb, $cloneDb);

    echo "Migração concluída. Aguardando 24 horas para a próxima execução...\n";
    sleep(86400); // Espera 24 horas
};
?>
