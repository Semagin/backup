<!DOCTYPE html>
<html lang="en">
    <head>
      <meta charset="utf-8">
      <title>Backup test</title>
    </head>
    <body>

    <h3>Input backup params:</h3>
    <form action="/backup/backup.php" method="post">
        <label for="filesize">Max backup file size, bytes: <input type="number" name="filesize1" value="2000000000" id="filesize"></label> <br>
        <label for="dbhost">DB host: <input type="text" name="dbhost" id="dbhost" value="127.0.0.1" required> </label> <br>
        <label for="dbname">DB name: <input type="text" name="dbname" id="dbname" required> </label> <br>
        <label for="dblogin">DB root login: <input type="text" name="dblogin" value="root" id="dblogin" required></label> <br>
        <label for="dbpasswd">DB root password: <input type="password" name="dbpasswd" value="root" id="dbpasswd" required></label> <br>
        <input type="submit" value="backup">
    </form>

        </body>
</html>
