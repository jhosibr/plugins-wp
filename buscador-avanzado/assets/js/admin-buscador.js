/**
 * Agrega una ruta nueva al listado en el dashboard
 */
function agregarRuta() {
    const rutaInput = document.getElementById('nueva_ruta');
    const ruta = rutaInput.value.trim();
    if (!ruta) return;
    const li = document.createElement('li');
    li.textContent = ruta;
    const btn = document.createElement('button'); btn.textContent='Quitar'; btn.onclick=()=>li.remove();
    li.appendChild(btn);
    document.getElementById('lista-rutas').appendChild(li);
    rutaInput.value='';
  }
  
  /**
   * Agrega un tipo de archivo nuevo
   */
  function agregarTipo() {
    const input = document.getElementById('nuevo_tipo');
    const ext = input.value.trim().toLowerCase();
    if (!ext) return;
    const label = document.createElement('label');
    const cb = document.createElement('input'); cb.type='checkbox'; cb.name='tipos[]'; cb.value=ext; cb.checked=true;
    label.appendChild(cb);
    label.append(' '+ext.toUpperCase());
    document.getElementById('tipos-form').appendChild(label);
    input.value='';
  }
  
  /**
   * Muestra/oculta el instructivo
   */
  function toggleInstructivo() {
    const ins = document.getElementById('instructivo');
    ins.style.display = ins.style.display==='none' ? 'block' : 'none';
  }