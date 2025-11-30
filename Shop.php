<!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Магазин драконьих товаров</title>
        <style>
            .hidden-page {
            display: none;
            padding: 20px;
            background: #eef;
            border: 1px solid #99c;
            margin-top: 20px;
            }

            #showPage:checked ~ .hidden-page {
                display: block;
            }
        </style>
    </head>
    <body>
        <?php
        require __DIR__ . '/db.php';
        $action = $_POST['action'] ?? '';
        $showSub = $action !== '';
        ?>

        <main class="basic">
            <?php if (!$showSub): ?>
                <h1>Магазин</h1>
                <?php if (isset($_GET['success'])): ?>
                    <?php  
                        $stmt = $pdo -> prepare("SELECT `productName` FROM `Goods1` WHERE `SKU` = :uid LIMIT 1");
                        $stmt -> execute([':uid' => $_GET['success']]);
                        $row = $stmt -> fetch(PDO::FETCH_ASSOC);

                        if ($row) {
                            echo '<h5>Добавлен товар:' . $row['productName'] . '</h5>';
                        }
                    ?>
                <?php endif; ?>
                <section id="">
                    <?php  
                    $categoryId = $_GET['category'] ?? null;
                    $SKUs = $_GET['SKU'] ?? null;

                    $names = [
                        0 => 'Хлеб',
                        1 => 'Напитки',
                        2 => 'Бакалея',
                        3 => 'Мясо',
                        4 => 'Молочная продукция',
                        5 => 'Овощи и фрукты',
                    ];

                    if ($categoryId === null) {
                        // Список категорий
                        $sql = 'SELECT DISTINCT `category` FROM `Goods1` WHERE `category` IS NOT NULL ORDER BY `category` ASC';
                        $stmt = $pdo -> prepare($sql);
                        $stmt -> execute();
                        $categories = $stmt -> fetchAll(PDO::FETCH_COLUMN);

                        foreach ($categories as $cat) {
                            $label = $names[$cat] ?? ('Категория ' . $cat);
                            echo '<a href="?category=' . htmlspecialchars($cat) . '">' . htmlspecialchars($label) . '</a><br>';
                        }
                    } 
                    else {
                        // Список товаров в категории
                        $stmt = $pdo -> prepare("SELECT `productName`, `SKU`, `type`  FROM `Goods1` WHERE `category` = :category");
                        $stmt -> execute([':category' => $categoryId]);
                        $products = $stmt -> fetchAll(PDO::FETCH_ASSOC);

                        $label = $names[$categoryId] ?? ('Категория' . $categoryId);
                        echo '<h1>Товары: ' . htmlspecialchars($label) . '</h1>';

                        echo '<ul>';
                        foreach ($products as $prod) {
                            $type = $prod['type'] ?? 'Штучный';
                            $step = ($type === 'Весовой') ? 100 : 1;

                            echo '<li> <a href="Util.php?action=minusproduct&SKU=' . $prod['SKU'] . '&step=' . $step .'">-</a>' . htmlspecialchars($prod['productName']) . 
                                      '<a href="Util.php?action=addproduct&SKU=' . $prod['SKU'] . '&step=' . $step .'">+</a> </li>';
                        }
                        echo '</ul>';
                        echo '<a href="Shop.php">Назад в магазин</a>';
                    }
                ?>
                </section>
                <section id="buttonPA">
                    <a href="PA.php">
                        Личный кабинет
                    </a>
                </section>
            <?php else: ?>
                <?php
                    require __DIR__ . '/db.php';
                    class Shop {
                        public function loadSubCategory(string $category, PDO $pdo) : array {
                            $stmt = $pdo -> prepare("SELECT DISTINCT `productName` FROM `Goods1` WHERE `category` = :category");
                            $stmt -> execute(['category' => $category]);
                            return $stmt -> fetchAll(PDO::FETCH_COLUMN);
                        }
                    }

                    $Shop = new Shop();

                    $action = $_POST['action'] ?? '';
                ?>
                <h1><?= htmlspecialchars($action) ?></h1>
                <form method="post">
                    <button type="submit">Назад в магазин</button>
                </form>
            <?php endif; ?>
        </main>
    </body>
</html>