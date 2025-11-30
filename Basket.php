<?php 
    require_once __DIR__ .'/Goods.php';
    require_once __DIR__ .'/db.php';
    require_once __DIR__ .'/Util.php';

    if (basename(__FILE__) !== basename($_SERVER['SCRIPT_FILENAME'])) {
        // При включении: завершаем исполнение этого файла, чтобы он не выводил свою HTML-страницу.
        return;
    }

    if (session_status() === PHP_SESSION_NONE) { 
        session_start();
    }

    class Basket {
        private PDO $pdo;
        private array $goodsArr = [];

        public function __construct(PDO $pdo) {
            $this -> pdo = $pdo;
        }

        public function getGoodsArr() : array {
            return $this -> goodsArr;
        }
        public function setGoodsArr($goods) : void {
            $this->goodsArr = $goods;
        }

        public function addGoodsArr(Goods $goods) : void {
            $this -> goodsArr[] = $goods;
        }

        public function addGoodsForUser(Goods $goods, int $userID, int $steep) : void {
            if ($userID <= 0) { 
                throw new InvalidArgumentException('Неверный userID');
            }

            $sku = trim($goods -> getSKU());

            if ($sku === '') { 
                throw new InvalidArgumentException('Пустой SKU');
            }

            if (isset($_SESSION['User']) && is_array($_SESSION['User']) && !empty($_SESSION['User']['ID'])) {
                $userID = (int)$_SESSION['User']['ID'];
            } 
            elseif (isset($_SESSION['User_ID'])) {
                $userID = (int)$_SESSION['User_ID'];
            }

            if ($goods -> getSKU() === '') {
                throw new InvalidArgumentException('Пустой SKU');
            } 

            // Подстраховка: используем транзакцию, чтобы операция была атомарной
            try {
                $this -> pdo -> beginTransaction();

                // 1) Пытаемся найти существующую открытуя корзину для user (status = 'open').
                //    ORDER BY ID DESC - на случай, если по глюку несколько, возьмём последнюю.
                //    Примечание: при сильной параллельности добавок рекомендовано SELECT ... FOR UPDATE
                //    или отдельная таблица basket_items. Здесь простой вариант.

                $find = $this -> pdo -> prepare("SELECT ID, Goods FROM Basket_User WHERE User_ID = :uid AND Status = 'open' ORDER BY ID DESC LIMIT 1");

                $find -> execute([':uid' => $userID]);
                $row = $find -> fetch(PDO::FETCH_ASSOC);

                $arr = [];

                if ($row) {
                    $basketID = (int)$row['ID'];
                    $raw = $row['Goods'] ?? '[]';
                    $arr = json_decode($raw, true);

                    if (!is_array($arr)) { 
                        $arr = [];
                    }

                    $map = [];

                    if (isset($arr[$goods -> getSKU()])) {
                        $arr[$goods -> getSKU()] += $steep;
                    }
                    else {
                        $arr[$goods -> getSKU()] = $steep;
                    }

                    // Сохранение
                    $upd = $this -> pdo -> prepare("UPDATE Basket_User SET Goods = :goods WHERE ID = :id");
                    $upd -> execute([
                        ':goods' => json_encode($arr, JSON_UNESCAPED_UNICODE),
                        ':id' => $basketID
                    ]);
                }
                else {
                    // нет open корзины, то создаём новую запись с одним элементом "SKU:1"
                    if (isset($arr[$goods -> getSKU()])) {
                        $arr[$goods -> getSKU()] += $steep;
                    }
                    else {
                        $arr[$goods -> getSKU()] = $steep;
                    }
                    $ins = $this -> pdo -> prepare("INSERT INTO Basket_User (User_ID, Status, Goods) VALUES (:uid, 'open', :goods)");
                    $ins -> execute([
                        ':uid' => $userID,
                        ':goods' => json_encode($arr, JSON_UNESCAPED_UNICODE)
                    ]);
                }

                $this -> pdo -> commit();
            }
            catch (Exception $e) {
                $this -> pdo -> rollBack();
                // Пробрасываем дальше - контроллер обработает ошибку/лог
                throw $e;
            }
        }

        public function decreaseGoodsForUser(string $sku, int $userID, int $steep): void {
            if ($userID <= 0) { 
                throw new InvalidArgumentException('Invalid userID');
            }

            $sku = trim($sku);

            if ($sku === '' || $userID <= 0) {
                return;
            }

            $steep = max(1, (int)$steep);

            try {
                $this -> pdo -> beginTransaction();

                // 1) Найти открытую корзину
                $stmt = $this -> pdo -> prepare("SELECT ID, Goods FROM Basket_User WHERE User_ID = :uid AND Status = 'open' ORDER BY ID DESC LIMIT 1");
                $stmt -> execute([':uid' => $userID]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $this -> pdo -> commit();
                    return;
                }

                $basketID = (int)$row['ID'];
                $raw = $row['Goods'] ?? '{}';
                $map = json_decode($raw, true);

                // Если не ассоциативный массив - инициализируем пустым
                if (!is_array($map)) { 
                    $map = [];
                }

                if (!isset($map[$sku])) {
                    $this->pdo->commit();
                    return;
                }

                // Уменьшаем количество и удаляем или обновляем товар(SKU)
                $newQty = (int)$map[$sku] - $steep;
                if ($newQty > 0) {
                    $map[$sku] = $newQty;
                } 
                else {
                    unset($map[$sku]);
                }

                // Сохраняем: если корзина пустая - помечаем cancelled
                if (count($map) === 0) {
                    $upd = $this -> pdo -> prepare("UPDATE Basket_User SET Goods = :goods, Status = 'cancelled' WHERE ID = :id");
                    $upd->execute([
                        ':goods' => json_encode($map, JSON_UNESCAPED_UNICODE), 
                        ':id' => $basketID
                    ]);
                } 
                else {
                    $upd = $this -> pdo -> prepare("UPDATE Basket_User SET Goods = :goods WHERE ID = :id");
                    $upd->execute([
                        ':goods' => json_encode($map, JSON_UNESCAPED_UNICODE),
                        ':id' => $basketID
                    ]);
                }

                $this->pdo->commit();
            } 
            catch (Exception $e) {
                $this->pdo->rollBack();
                throw $e;
            }
        }

        /**
        * Получить массив товаров текущей open корзины как массив ассоциативных элементов.
        * Вернёт [] если корзины нет.
        * Полезная штука для отображения товаров в корзине.
        */
        public function getOpenBasketItemsForUser(int $userID) : array {
            if ($userID <= 0) { 
                return [];
            }

            $stmt = $this -> pdo -> prepare("SELECT Goods FROM Basket_User WHERE User_ID = :uid AND Status = 'open' ORDER BY ID DESC LIMIT 1");
            $stmt -> execute([':uid' => $userID]);
            $row = $stmt -> fetch(PDO::FETCH_ASSOC);

            if (!$row) { 
                return [];
            }

            $items_json = json_decode($row['Goods'], true);

            if (!is_array($items_json) || empty($items_json)) {
                return [];
            }

            $basketItems = [];
            foreach ($items_json as $sku => $quantity) {
                $basketItems[(string)$sku] = (int)$quantity; // Используем SKU
            }

            $skus = array_keys($basketItems);
            $placeholders = rtrim(str_repeat('?,', count($skus)), ',');

            $sql = "SELECT SKU, productName, price FROM Goods1 WHERE SKU IN ($placeholders)";
            $stmt = $this -> pdo -> prepare($sql);

            $stmt->execute($skus);
            $goodsInfo = $stmt -> fetchAll(PDO::FETCH_ASSOC);

            $finalItems = [];
            foreach ($goodsInfo as $good) {
                $sku = $good['SKU'];
                
                // Если товар был в корзине, добавляем его в массив
                if (isset($basketItems[$sku])) {
                    $finalItems[] = [
                        'productName' => $good['productName'],
                        'price' => (float)$good['price'],
                        'Quantity' => $basketItems[$sku] // Добавляем количество из корзины
                    ];
                }
            }

            return $finalItems;
        }
    }

    $userID = 0;

    $basket = new Basket($pdo);

    if (isset($_SESSION['User']) && is_array($_SESSION['User']) && !empty($_SESSION['User']['ID'])) {
        $userID = (int)$_SESSION['User']['ID'];
    } 
    elseif (isset($_SESSION['User_ID'])) {
        $userID = (int)$_SESSION['User_ID'];
    }

    $basketItems = $basket -> getOpenBasketItemsForUser($userID);
?>

<!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Магазин драконьих товаров (корзина)</title>
        <style>
            /* .hidden-page {
                display: none;
                padding: 20px;
                background: #eef;
                border: 1px solid #99c;
                margin-top: 20px;
            }

            #showPage:checked ~ .hidden-page {
                display: block;
            } */
                
            table { border-collapse: collapse; width: 100%; }
            th, td { padding: 8px 10px; border: 1px solid #ddd; text-align: left; }
            .total { font-weight: 700; }
        </style>
    </head>
    <body>
        <main class="basic">
            <h1>Корзина</h1>

            <!--
                Проверяем, есть ли вообще элементы в корзине.
                empty($basketItems) вернёт true если:
                - переменная не определена,
                - или равна пустому массиву [],
                - или равна null/пустой строке/0 и т.п.
                Это удобная защита: если корзина пуста или переменная не задана,
                мы выводим сообщение и не пытаемся пройтись циклом.
            -->
            <?php if (empty($basketItems)): ?>
                <p>Ваша корзина пуста.</p>
            <?php else: ?>
                <!-- Если пришли сюда - в $basketItems есть хотя бы один элемент.
                    Начинаем рисовать таблицу с товарами. -->
                <table>
                    <thead>
                        <tr>
                            <th>
                                Наименование товара:
                            </th>
                            <th>
                                Цена за шт.:
                            </th>
                            <th>
                                Кол-во:
                            </th>
                            <th>
                                Сумма:
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // Инициализация общей суммы.
                        $grandTotal = 0.0;

                        // $basketItems должен быть массивом,
                        // где каждый элемент - ассоциативный массив с данными о товаре.
                        foreach ($basketItems as $item) : 
                            /*
                            * Здесь мы НЕ меняем структуру $item, а лишь берём из нее нужные значения.
                            */
                            
                            // 1) Получаем имя товара.
                            //    Используем оператор null coalescing (??) - берёт первый существующий а не-null вариант.
                            //    Поддерживаем оба варианта ключа: 'ProductName' и 'productName',
                            //    потому что в разных частях кода может быть по разному(я забываю переодически как там эти стобцы в бд называются :р).
                            //    Если оба отсутствуют - ставим понятный дефолт 'Без названия'.
                            $name = $item['ProductName'] ?? $item['productName'] ?? 'Без названия';
                        
                            // 2) Получаем цену.
                            //    Тут используем isset() + какие-то тернарные выражения(я так и не понял что это значит), чтобы отличать:
                            //    - ключ отсутствует => взять альтернативный ключ или поставить 0.0,
                            //    - ключ есть, но значение может быть строкой - приводим к float.
                            //    (float) гарантирует, что в $price будет число - важно для number_format и умножений.
                            $price = isset($item['Price']) ? (float)$item['Price'] : (isset($item['price']) ? (float)$item['price'] : 0.0);
                            
                            // 3) Получаем количество.
                            //    Аналогично цене: берём 'Quantity' или 'quantity' и приводим к int.
                            //    Если ничего нет - ставим 0 (безопасный запас).
                            $qty = isset($item['Quantity']) ? (int)$item['Quantity'] : (isset($item['quantity']) ? (int)$item['quantity'] : 0);

                            // 4) Считаем сумму по позиции и аккумулируем в общей сумме.
                            //    $itemTotal - это число, результат умножения float * int => float.
                            $itemTotal = $price * $qty;
                            $grandTotal += $itemTotal;
                        ?>
                            <tr>
                                <td>
                                    <!--
                                        htmlspecialchars защищает от XSS: делает безопасным вывод текста,
                                        экранируя символы <, >, &, кавычки и т.д.
                                        ENT_QUOTES - экранирует и ' и ".
                                        'UTF-8' - указываем кодировку явно (важно для кириллицы).
                                    -->
                                    <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <!--
                                        number_format форматирует число: 
                                        первый параметр - число,
                                        2 - количество знаков после запятой,
                                        ',' - разделитель дробной части,
                                        ' ' - разделитель тысяч (пробел).
                                        В результате будет выглядеть как: 1 234,50
                                    -->
                                    <?= number_format($price, 2, ',', ' ') ?> руб.
                                </td>
                                <td>
                                    <!-- Количество выводим как целое -->
                                    <?= $qty ?> шт.
                                </td>
                                <td>
                                    <!-- Сумма по строке -->
                                    <?= number_format($itemTotal, 2, ',', ' ') ?> руб.
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <!--
                                colspan="3" объединяет три колонки, чтобы ячейка "Итого:" занимала место под первыми тремя колонками, а в последней была сама сумма.
                            -->
                            <td colspan="3" class="total">
                                Итого:
                            </td>
                            <td class="total">
                                <!-- Итоговая аккумулированная сумма, отформатированная так же, как и остальные -->
                                <?= number_format($grandTotal, 2, ',', ' ') ?> руб.
                            </td>
                        </tr>
                    </tfoot>
                </table>

                <section id="buttonBuy">
                    <a href="Util.php?action=buy">
                        Купить
                    </a>
                </section>
                <section id="buttonClear">
                    <a href="Util.php?action=clear">
                        Очистить корзину
                    </a>
                </section>
                <section id="buttonPA">
                    <a href="PA.php">В ЛК</a>
                </section>
            <?php endif; ?>
        </main>
    </body>
</html>