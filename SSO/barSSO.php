<?php
require("config");
if (!isset($_SESSION["attributes"])) {
}
else{
?>
<style>
.sso-bar{
    width:100%;
    background:#f4f6f8;
    border-bottom:1px solid #ddd;
    font-family:Arial, sans-serif;
    font-size:13px;
    padding:6px 12px;
    box-sizing:border-box;
}

.sso-content{
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.sso-left{
    color:#333;
}

.sso-left span{
    margin-right:15px;
}

.sso-logout{
    color:#666;
    text-decoration:none;
    font-size:12px;
}

.sso-logout:hover{
    text-decoration:underline;
}
</style>

<div class="sso-bar">
<div class="sso-content">

<div class="sso-left">
<span><b><?php echo $_SESSION["prenom_sso"]." ".$_SESSION["nom_sso"]; ?></b></span>
<span><?php echo $_SESSION["matricule_sso"]; ?></span>
<span><?php echo $_SESSION["poste_sso"]; ?></span>
<span><?php echo $_SESSION["service_sso"]; ?></span>
</div>

<div>
<a class="sso-logout" href="<?php echo $linkLogout; ?>">Déconnexion</a>
</div>

</div>
</div>
<?php
}
?>