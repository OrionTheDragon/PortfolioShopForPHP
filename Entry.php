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
                <section id="logUserName">
                    <input type="text" id="user_name" name="logusername" maxlength="30" placeholder="Логин" required value="">
                </section>
                <section id="logUserPass">
                    <input type="password" id="user_pass" name="loguserpass" maxlength="100" placeholder="Пароль" required value="">
                </section>
                <section id="buttonEntry">
                    <button type="submit" name="action" value="load_user">
                        Войти
                    </button>
                </section>
                <section id="buttonBack">
                    <a href="index.html">Назад</a>
                </section>
            </form>
        </main>
    </body>
</html>