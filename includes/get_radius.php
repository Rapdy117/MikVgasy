<?php
/*
 *  Copyright (C) 2018 Laksamadi Guko.
 */

error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
  exit;
}

if (isset($_POST['save'])) {

$radius_host = $_POST['radius_host'];
$radius_db   = $_POST['radius_db'];
$radius_user = $_POST['radius_user'];
$radius_pass = $_POST['radius_pass'];

$configFile = "./include/config.php";

$content = file_get_contents($configFile);

$newConfig = "\n\$radius = array(
'host' => '$radius_host',
'db'   => '$radius_db',
'user' => '$radius_user',
'pass' => '$radius_pass'
);\n";

if(strpos($content,"\$radius") === false){
file_put_contents($configFile,$content.$newConfig);
}

$conn = @mysqli_connect($radius_host,$radius_user,$radius_pass,$radius_db);

if($conn){
$_SESSION["connect"] = "<b class='text-green'>Radius Connected</b>";
mysqli_close($conn);
}else{
$_SESSION["connect"] = "<b class='text-red'>Radius Not Connected</b>";
}

}
?>

<form autocomplete="off" method="post" action="" name="settings">

<div class="row">

<div class="col-12">
<div class="card">

<div class="card-header">
<h3 class="card-title">
<i class="fa fa-gear"></i> <?= $_session_settings ?>
</h3>
</div>

<div class="card-body">
<div class="row">

<!-- LEFT COLUMN -->
<div class="col-6">

<!-- SESSION -->
<div class="card">
<div class="card-header">
<h3 class="card-title"><?= $_session ?></h3>
</div>

<div class="card-body">
<table class="table">

<tr>
<td><?= $_session_name ?></td>
<td>
<input class="form-control"
id="sessname"
type="text"
name="sessname"
value="<?php
if (explode("-",$session)[0] == "new"){
  echo "";
}else{
  echo $session;
}
?>"
required>
</td>
</tr>

</table>
</div>
</div>

<!-- FREERADIUS -->
<div class="card">
<div class="card-header">
<h3 class="card-title">FreeRADIUS Database</h3>
</div>

<div class="card-body">

<table class="table table-sm">

<tr>
<td>Radius Host</td>
<td>
<input class="form-control" type="text"
name="radius_host"
value="<?= $radius_host ?? '127.0.0.1'; ?>">
</td>
</tr>

<tr>
<td>Database</td>
<td>
<input class="form-control" type="text"
name="radius_db"
value="<?= $radius_db ?? 'radius'; ?>">
</td>
</tr>

<tr>
<td>User</td>
<td>
<input class="form-control" type="text"
name="radius_user"
value="<?= $radius_user ?? ''; ?>">
</td>
</tr>

<tr>
<td>Password</td>
<td>
<input class="form-control"
type="password"
name="radius_pass">
</td>
</tr>

<tr>
<td colspan="2">
<input class="group-item group-item-md"
type="submit"
name="save"
value="Save">
</td>
</tr>

</table>

</div>
</div>

</div>


<!-- RIGHT COLUMN -->
<div class="col-6">

<div class="card">

<div class="card-header">
<h3 class="card-title">Mikhmon Data</h3>
</div>

<div class="card-body">

<table class="table table-sm">

<tr>
<td><?= $_hotspot_name ?></td>
<td>
<input class="form-control"
name="hotspotname"
value="<?= $hotspotname; ?>">
</td>
</tr>

<tr>
<td><?= $_dns_name ?></td>
<td>
<input class="form-control"
name="dnsname"
value="<?= $dnsname; ?>">
</td>
</tr>

<tr>
<td><?= $_currency ?></td>
<td>
<input class="form-control"
name="currency"
value="<?= $currency; ?>">
</td>
</tr>

<tr>
<td><?= $_auto_reload ?></td>
<td>
<input class="form-control"
type="number"
name="areload"
value="<?= $areload; ?>">
</td>
</tr>

<tr>
<td><?= $_traffic_interface ?></td>
<td>
<input class="form-control"
type="number"
name="iface"
value="<?= $iface; ?>">
</td>
</tr>

</table>

</div>
</div>

</div>

</div>
</div>
</div>
</div>

</form>