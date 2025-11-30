<?php
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    require_once __DIR__ .'/Goods.php';
    require_once __DIR__ .'/db.php';
    require_once __DIR__ .'/Util.php';

    if (basename(__FILE__) !== basename($_SERVER['SCRIPT_FILENAME'])) {
        // При включении: завершаем исполнение этого файла, чтобы он не выводил свою HTML-страницу.
        return;
    }

    Class History {
        private PDO $pdo;

        public function __construct(PDO $pdo) {
            $this -> pdo = $pdo;
        }

        public function prevHistory(int $userID) : array {
            $stmt = $this -> pdo -> prepare("SELECT ID, Goods, Status FROM Basket_User WHERE User_ID = :uid AND Status IN ('orders','cleared')");
            $stmt -> execute([':uid' => $userID]);
            $rows = $stmt -> fetchAll(PDO::FETCH_ASSOC);

            if (!$rows) {
                return [
                    'orders' => [],
                    'cleared' => []
                ];
            }

            $result = [
                'orders' => [],
                'cleared' => []
            ];

            foreach ($rows as $row) {
                $basketID = (int)$row['ID'];

                $raw = $row['Goods'] ?? '{}';
                $items_json = json_decode($raw, true);

                if (!is_array($items_json) || empty($items_json)) {
                    continue;
                }

                $basketItems = [];
                foreach ($items_json as $sku => $quantity) {
                    $basketItems[(string)$sku] = (int)$quantity;
                }

                $skus = array_keys($basketItems);
                $placeholders = rtrim(str_repeat('?,', count($skus)), ',');

                $sql = "SELECT SKU, productName, price FROM Goods1 WHERE SKU IN ($placeholders)";
                $stmt2 = $this->pdo->prepare($sql);
                $stmt2->execute($skus);
                $goodsInfo = $stmt2->fetchAll(PDO::FETCH_ASSOC);

                $finalItems = [];
                foreach ($goodsInfo as $good) {
                    $sku = $good['SKU'];
                    if (isset($basketItems[$sku])) {
                        $finalItems[] = [
                            'productName' => $good['productName'],
                            'price' => (float)$good['price'],
                            'Quantity' => $basketItems[$sku]
                        ];
                    }
                }

                $result[$row['Status']][] = [
                    'basketID' => $basketID, // ID корзины
                    'items' => $finalItems // Товары в этой корзине
                ];
            }

            return $result;
        }
    }
    $userID = 0;

    if (isset($_SESSION['User']) && is_array($_SESSION['User']) && !empty($_SESSION['User']['ID'])) {
        $userID = (int)$_SESSION['User']['ID'];
    } 
    elseif (isset($_SESSION['User_ID'])) {
        $userID = (int)$_SESSION['User_ID'];
    }

    $history = new History($pdo);
    
    $basketItems = $history -> prevHistory($userID);

    $statusNames = [
    'orders' => 'Оформлен',
    'cleared' => 'Отменён'
    ];
?>

<!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Магазин драконьих товаров (История)</title>
        <style>
            table { border-collapse: collapse; width: 100%; margin-bottom: 30px; }
            th, td { padding: 8px 10px; border: 1px solid #ddd; text-align: left; }
            .total { font-weight: 700; }
            .basket-title { background: #f0f0f0; font-weight: bold; }
        </style>
    </head>
    <body>
        <main class="basic">
            <h1>История заказов</h1>

            <?php if (empty($basketItems['orders']) && empty($basketItems['cleared'])): ?>
                <p>История пуста</p>
            <?php else: ?>
                <?php foreach (['orders', 'cleared'] as $status): ?>
                    <?php if (!empty($basketItems[$status])): ?>
                        <h2><?= $status === 'orders' ? 'Оформленые' : 'Отменёные' ?></h2>

                        <?php foreach ($basketItems[$status] as $basket): ?>
                            <table>
                                <thead>
                                    <tr class="basket-title">
                                        <th colspan="10">
                                            Статус корзины: (<?= $statusNames[$status] ?>)
                                        </th>
                                    </tr>
                                    <tr>
                                        <th>
                                            Наименование товара
                                        </th>
                                        <th>
                                            Цена за шт.
                                        </th>
                                        <th>
                                            Кол-во
                                        </th>
                                        <th>
                                            Сумма
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                        $basketTotal = 0.0;
                                        foreach ($basket['items'] as $item):
                                            $itemTotal = $item['price'] * $item['Quantity'];
                                            $basketTotal += $itemTotal;
                                    ?>
                                        <tr>
                                            <td>
                                                <?= htmlspecialchars($item['productName'], ENT_QUOTES, 'UTF-8') ?>
                                            </td>
                                            <td>
                                                <?= number_format($item['price'], 2, ',', ' ') ?> руб.
                                            </td>
                                            <td>
                                                <?= $item['Quantity'] ?> шт.
                                            </td>
                                            <td>
                                                <?= number_format($itemTotal, 2, ',', ' ') ?> руб.
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="3" class="total">
                                            Итого по корзине:
                                        </td>
                                        <td class="total">
                                            <?= number_format($basketTotal, 2, ',', ' ') ?> руб.
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </main>
    </body>
</html>