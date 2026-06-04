<?php
session_start();
session_destroy();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Auvergne Habitat - Déconnexion</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; font-family: Arial, sans-serif; }

        body {
            background: #f7f7f7;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }

        .logout-container {
            background: #ffffff;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
            text-align: center;
            width: 400px;
        }

        .logout-container img {
            max-width: 150px;
            margin-bottom: 30px;
        }

        h1 {
            color: #005a9c; /* Couleur charte Auvergne Habitat */
            margin-bottom: 20px;
            font-size: 24px;
        }

        p {
            color: #333333;
            margin-bottom: 20px;
        }

        a.button {
            display: inline-block;
            padding: 10px 20px;
            background-color: #005a9c;
            color: #ffffff;
            text-decoration: none;
            border-radius: 8px;
            font-size: 16px;
        }

        a.button:hover {
            background-color: #004080;
        }
    </style>
</head>
<body>
    <div class="logout-container">
        <img src="img/logo.png" alt="Auvergne Habitat">
        <h1>Déconnexion réussie</h1>
        <p>Vous êtes à présent déconnecté de l'application.</p>
        <a class="button" href="authSSO.php">Retour à la page de connexion</a>
    </div>
</body>
</html>