<!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Магазин драконьих товаров</title>
        <style>
            .hidden { display: none; }
        </style>
    </head>
    <body>
        <main class="basic">
            <form action="Util.php" method="post">
                <section id="input_name">
                    <h1>Регистрация</h1>
                    <input type="text" id="user_name" name="username" maxlength="30" placeholder="Имя" required value="">
                </section>
                <section id="input_pass">
                    <input type="password" id="user_pass" name="userpass" maxlength="100" placeholder="Пароль" required value="">
                </section>
                <section id="input_money">
                    <input type="value" id="user_money" name="usermoney" maxlength="15" placeholder="Количество ваших денег" required value="">
                </section>
                <section id="input_age">
                    <label for="user_age">Сколько вам лет?</label>
                    <select name="user_age" id="user_age">
                        <?php
                        $minValue = 14;
                        $maxValue = 100;

                        for($i = $minValue; $i < $maxValue; $i++) {
                            echo "<option value=\"$i\">$i</option>";
                        }
                        ?>
                    </select>
                </section>
                <section id="button_save">
                    <button type="submit" name="action" value="save_user">
                        Сохранить
                    </button>
                </section>
            </form>
            <section id="button_back">
                <a href="index.html">Назад</a>
            </section>
        </main>
    </body>
</html>