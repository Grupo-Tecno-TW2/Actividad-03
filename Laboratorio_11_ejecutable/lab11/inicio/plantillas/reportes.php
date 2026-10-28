<?php /** plantillas/reportes.php — Reportes del período. */ ?>
<h2>Reportes</h2>
<form method="get" action="/reportes" class="filtros">
  <label for="desde">Desde</label> <input id="desde" name="desde" type="date" value="<?= $desde ?>">
  <label for="hasta">Hasta</label> <input id="hasta" name="hasta" type="date" value="<?= $hasta ?>">
  <button type="submit">Ver</button> <a href="<?= $csv ?>">Descargar detalle (CSV)</a>
</form>
<h3>Ventas por mes</h3>
<table id="por-mes"><thead><tr><th scope="col">Mes</th><th scope="col">Ventas</th><th scope="col">Total</th></tr></thead><tbody>
<?php foreach ($porMes as $f): ?><tr><td><?= $f['mes'] ?></td><td class="num"><?= $f['ventas'] ?></td><td class="num"><?= $f['total'] ?></td></tr><?php endforeach; ?>
</tbody></table>
<h3>Los 5 productos que más venden</h3>
<table id="top"><thead><tr><th scope="col">Código</th><th scope="col">Producto</th><th scope="col">Unidades</th><th scope="col">Importe</th></tr></thead><tbody>
<?php foreach ($top as $f): ?><tr><td><?= $f['codigo'] ?></td><td><?= $f['nombre'] ?></td><td class="num"><?= $f['unidades'] ?></td><td class="num"><?= $f['importe'] ?></td></tr><?php endforeach; ?>
</tbody></table>
<h3>Socios con ventas de Bs 3.000 o más (HAVING)</h3>
<table id="por-socio"><thead><tr><th scope="col">Socio</th><th scope="col">Comunidad</th><th scope="col">Importe</th></tr></thead><tbody>
<?php foreach ($porSocio as $f): ?><tr><td><?= $f['socio'] ?></td><td><?= $f['comunidad'] ?></td><td class="num"><?= $f['importe'] ?></td></tr><?php endforeach; ?>
</tbody></table>
<h3>Productos sin ventas en el período</h3>
<ul id="sin-ventas"><?php foreach ($sinVentas as $f): ?><li><?= $f['codigo'] ?> — <?= $f['nombre'] ?> (stock <?= $f['stock'] ?>)</li><?php endforeach; ?></ul>
