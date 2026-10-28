<?php /** plantillas/venta.php — Venta de hasta 3 productos (una transacción) y acopio rápido. */ ?>
<h2>Nueva venta</h2>
<?php if ($mensaje !== ''): ?><p class="exito" role="status"><?= $mensaje ?></p><?php endif; ?>
<?php if ($errores !== []): ?><p class="alerta" role="alert"><?= implode(' ', $errores) ?></p><?php endif; ?>
<form method="post" action="/ventas">
  <input type="hidden" name="_csrf" value="<?= $csrf ?>">
  <p><label for="cliente">Cliente</label> <input id="cliente" name="cliente" value="<?= $cliente ?>" required maxlength="80"></p>
  <table>
    <thead><tr><th scope="col">Código</th><th scope="col">Cantidad</th></tr></thead>
    <tbody>
    <?php foreach ($lineas as $i => $l): ?>
      <tr>
        <td><input name="codigo[<?= $i ?>]" value="<?= $l['codigo'] ?>" aria-label="Código del producto <?= $i + 1 ?>" maxlength="8"></td>
        <td><input name="cantidad[<?= $i ?>]" value="<?= $l['cantidad'] ?>" aria-label="Cantidad <?= $i + 1 ?>" inputmode="numeric" size="4"></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <button type="submit">Registrar venta</button>
</form>
<h2>Acopio (un socio entrega producto)</h2>
<form method="post" action="/acopios" class="filtros">
  <input type="hidden" name="_csrf" value="<?= $csrf ?>">
  <label for="a-codigo">Código</label> <input id="a-codigo" name="codigo" maxlength="8">
  <label for="a-cantidad">Cantidad</label> <input id="a-cantidad" name="cantidad" inputmode="numeric" size="4">
  <label for="a-precio">Precio pagado (Bs)</label> <input id="a-precio" name="precio" inputmode="decimal" size="6">
  <button type="submit">Registrar acopio</button>
</form>
