<?php
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    require_once __DIR__ . '/Util.php';
    require_once __DIR__ . '/User.php';
    require_once __DIR__ . '/db.php';

    class PA {
        private PDO $pdo;
        public User $user;
        public $id = 0;
        public $name = '';
        public $money = 0;

        public function __construct(PDO $pdo) {
            $this -> pdo = $pdo;

            if (!empty($_SESSION['user'])) { // я забыл какой id использую, с большой или маленькой :р 
                if (isset($_SESSION['user']['ID'])) {
                    $this -> id = (int) $_SESSION['user']['ID'];
                } 
                elseif (isset($_SESSION['user']['id'])) {
                    $this -> id = (int) $_SESSION['user']['id'];
                }
            }

            if (isset($_SESSION['user'])) {
                $this -> name = (string) ($_SESSION['user']['name'] ?? '');
                $this -> money = (float) ($_SESSION['user']['money'] ?? 0.0);
            }

            $this -> user = new User($this -> id, $this -> name, '', $this -> money);
            if ($this -> user) {
                $this -> getUserMoney();
                include'PA.phtml';
            }
        }

        public function getUser(): User { 
            return $this -> user; 
        }
        public function getId(): int {
            return $this -> id;
        }
        public function getName(): string { 
            return $this -> name; 
        }
        public function getMoney(): float { 
            return $this -> money;
        }
        public function setMoney(float $mone): void {
            $this -> money = $mone;
        }

        public function getUserMoney() : void {
            $stmt = $this -> pdo -> prepare("SELECT Money FROM User_data WHERE ID = :id");
            $stmt -> execute([':id' => $this -> getId()]);
            $row = $stmt -> fetch(PDO::FETCH_ASSOC);

            $this -> setMoney((float)$row['Money']);
        }

        public static function accessCheck($ID) : bool {
            global $pdo;
            $stmt = $pdo -> prepare("SELECT Access FROM User_data WHERE ID = :id");
            $stmt -> execute([':id' => $ID]);
            $row = $stmt -> fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return false;
            }

            return ($row['Access'] === 'admin');
        }
    }

    // var_dump($_SERVER); exit;

    if (str_contains($_SERVER['REQUEST_URI'], '/PA.php')) {
        $pa = new PA($pdo);
    }
?>