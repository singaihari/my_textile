<div class="content" style="max-width:680px;margin:8vh auto">
  <div class="card card-pad">
    <h2>Database update required</h2>
    <p>This version of the application needs to update the shop database (<?= e(implode(', ', $pending)) ?>). For safety the update is not run from the browser.</p>
    <p>On the shop PC, open PowerShell in the application folder and run:</p>
    <pre class="card card-pad mono">php scripts\run-migrations.php</pre>
    <p class="muted">The script takes a verified backup first, then applies the update.</p>
  </div>
</div>
