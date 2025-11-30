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

            $util = new Util($this -> pdo);

            // $fresh = $util->getUserData($pdo, $this -> id);

            $this -> user = new User($this -> id, $this -> name, '', $this -> money);
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

        public function getUserMoney(PA $pa) : void {
            $stmt = $this -> pdo -> prepare("SELECT Money FROM User_data WHERE ID = :id");
            $stmt -> execute([':id' => $pa -> getId()]);
            $row = $stmt -> fetch(PDO::FETCH_ASSOC);

            $pa -> setMoney((float)$row['Money']);
        }

        public function accessCheck(PA $pa) : bool {
            $stmt = $this -> pdo -> prepare("SELECT Access FROM User_data WHERE ID = :id");
            $stmt -> execute([':id' => $pa -> getId()]);
            $row = $stmt -> fetch(PDO::FETCH_ASSOC);

            // if ($row['Access'] === 'admin') {
            //     return true;
            // }
            // else {
            //     return false;
            // }

            if (!$row) {
                return false;
            }

            return ($row['Access'] === 'admin');
        }
    }

    $pa = new PA($pdo);

    $pa -> getUserMoney($pa);
?>
<!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Магазин драконьих товаров</title>
    </head>
    <body>
        <main class="basic">
            <h1>ЛК</h1>
            <section id="head_pole">
                <ul>
                    <li>
                        <?= htmlspecialchars("Имя пользователя: " . $pa -> getName()) ?><br>
                        <?= htmlspecialchars("Деньги пользователя: " . $pa -> getMoney()) ?>
                    </li>
                </ul>
            </section>
            <section id="backShop">
                <a href="Shop.php">
                    Назад в магазин
                </a>
            </section>
            <section id="openBasket">
                <a href="Basket.php" target="_blank" rel="noopener noreferrer">
                    Открыть корзину
                </a>
            </section>
            <section id="openHustory">
                <a href="History.php" target="_blank" rel="noopener noreferrer">
                    История
                </a>
            </section>
            <?php
                $check = $pa -> accessCheck($pa);
                if ($check) {
                    echo "<section id='adminPanel'>
                            <a href='AdminPanel.php'>
                                Админ панель
                            </a>
                          </section>";
                }
            ?>
            <section id="exit">
                <a href="Util.php?action=logout">
                    Выход
                </a>
            </section>
        </main>
    </body>
</html>