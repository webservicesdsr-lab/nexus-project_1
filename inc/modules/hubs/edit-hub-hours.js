/**
 * Kingdom Nexus — Edit Hub Hours JS (v11.0)
 *  - UI 12h (HH / MM / AM/PM)
 *  - Guarda en 24h para el engine
 *  - Sunday bloqueado
 *  - 2nd shift sólo si la casilla está activa
 */

document.addEventListener('DOMContentLoaded', () => {
  const container = document.getElementById('knxHoursContainer');
  const saveBtn   = document.getElementById('knxSaveHoursBtn');

  if (!container || !saveBtn) return;

  // ==========================
  // Helpers
  // ==========================
  function to24Hour(hh, mm, ampm) {
    if (!hh || !mm) return '';
    let h = parseInt(hh, 10);
    if (Number.isNaN(h) || h < 1 || h > 12) return '';

    const ap = (ampm || 'AM').toUpperCase();

    if (ap === 'AM') {
      if (h === 12) h = 0;
    } else if (ap === 'PM') {
      if (h !== 12) h += 12;
    }

    const hStr = String(h).padStart(2, '0');
    return `${hStr}:${mm}`;
  }

  function toggleDayRow(row, enabled) {
    const dayCheck    = row.querySelector('.day-check');
    const secondCheck = row.querySelector('.second-check');
    const selects     = row.querySelectorAll('.time-select');
    const secondRange = row.querySelectorAll('.second-range');

    selects.forEach(sel => {
      if (sel.dataset.forceDisabled === 'true') return;
      sel.disabled = !enabled;
    });

    if (secondCheck && !secondCheck.disabled) {
      secondCheck.disabled = !enabled;
    }

    // Si desactivamos el día, también apagamos 2nd shift visualmente
    if (!enabled) {
      secondRange.forEach(sr => sr.classList.add('disabled'));
    } else {
      // estado depende de la casilla 2nd
      if (secondCheck && secondCheck.checked) {
        secondRange.forEach(sr => sr.classList.remove('disabled'));
      } else {
        secondRange.forEach(sr => sr.classList.add('disabled'));
      }
    }

    row.classList.toggle('row-disabled', !enabled);
  }

  function toggleSecondRow(row, enabled) {
    const selects     = row.querySelectorAll(
      '.open2, .open2m, .open2ampm, .close2, .close2m, .close2ampm'
    );
    const secondRange = row.querySelectorAll('.second-range');

    selects.forEach(sel => {
      if (sel.dataset.forceDisabled === 'true') return;
      sel.disabled = !enabled;
    });

    secondRange.forEach(sr => {
      sr.classList.toggle('disabled', !enabled);
    });
  }

  // ==========================
  // Inicializar filas
  // ==========================
  const rows = container.querySelectorAll('.knx-hours-row');

  rows.forEach(row => {
    const daySlug     = row.dataset.day || '';
    const isSunday    = daySlug === 'sunday';
    const dayCheck    = row.querySelector('.day-check');
    const secondCheck = row.querySelector('.second-check');
    const secondRange = row.querySelectorAll('.second-range');
    const selects     = row.querySelectorAll('.time-select');

    // Sunday: completamente bloqueado
    if (isSunday) {
      if (dayCheck) {
        dayCheck.checked = false;
        dayCheck.disabled = true;
      }
      selects.forEach(sel => {
        sel.disabled = true;
        sel.dataset.forceDisabled = 'true';
      });
      if (secondCheck) {
        secondCheck.checked = false;
        secondCheck.disabled = true;
      }
      secondRange.forEach(sr => sr.classList.add('disabled'));
      return;
    }

    // Estado inicial normal
    const isOpenToday   = dayCheck ? dayCheck.checked : false;
    const hasSecondOpen = secondCheck ? secondCheck.checked : false;

    toggleDayRow(row, isOpenToday);
    toggleSecondRow(row, isOpenToday && hasSecondOpen);

    // Eventos
    if (dayCheck) {
      dayCheck.addEventListener('change', () => {
        const enabled = dayCheck.checked;
        toggleDayRow(row, enabled);

        // Si se desactiva el día -> desactivar 2nd
        if (!enabled && secondCheck) {
          secondCheck.checked = false;
          toggleSecondRow(row, false);
        }
      });
    }

    if (secondCheck) {
      secondCheck.addEventListener('change', () => {
        const enabled = !!secondCheck.checked && (!!dayCheck ? dayCheck.checked : true);
        toggleSecondRow(row, enabled);
      });
    }
  });

  // ==========================
  // Guardar horas
  // ==========================
  saveBtn.addEventListener('click', async () => {
    const hubId = saveBtn.dataset.hubId;
    const nonce = saveBtn.dataset.nonce;

    if (!hubId || !nonce) {
      knxToast('Missing hub ID or nonce.', 'error');
      return;
    }

    const payload = {
      hub_id: hubId,
      knx_nonce: nonce,
      hours: {}
    };

    let invalid = false;
    rows.forEach(row => {
      const day = row.dataset.day;
      if (!day || day === 'sunday') {
        // Sunday se ignora: siempre cerrado desde el editor
        return;
      }

      const dayCheck    = row.querySelector('.day-check');
      const secondCheck = row.querySelector('.second-check');

      const open1      = row.querySelector('.open1')?.value || '';
      const open1m     = row.querySelector('.open1m')?.value || '';
      const open1ampm  = row.querySelector('.open1ampm')?.value || '';
      const close1     = row.querySelector('.close1')?.value || '';
      const close1m    = row.querySelector('.close1m')?.value || '';
      const close1ampm = row.querySelector('.close1ampm')?.value || '';

      const open2      = row.querySelector('.open2')?.value || '';
      const open2m     = row.querySelector('.open2m')?.value || '';
      const open2ampm  = row.querySelector('.open2ampm')?.value || '';
      const close2     = row.querySelector('.close2')?.value || '';
      const close2m    = row.querySelector('.close2m')?.value || '';
      const close2ampm = row.querySelector('.close2ampm')?.value || '';

      const isOpenToday   = dayCheck ? dayCheck.checked : false;
      const hasSecond     = secondCheck ? secondCheck.checked : false;
      const intervals     = [];

      // Primer turno
      if (isOpenToday) {
        if (!(open1 && open1m && open1ampm && close1 && close1m && close1ampm)) {
          invalid = true;
        }
        const open24  = to24Hour(open1, open1m, open1ampm);
        const close24 = to24Hour(close1, close1m, close1ampm);
        if (open24 && close24) {
          intervals.push({ open: open24, close: close24 });
        }
      }

      // Segundo turno
      if (isOpenToday && hasSecond) {
        if (!(open2 && open2m && open2ampm && close2 && close2m && close2ampm)) {
          invalid = true;
        }
        const open24  = to24Hour(open2, open2m, open2ampm);
        const close24 = to24Hour(close2, close2m, close2ampm);
        if (open24 && close24) {
          intervals.push({ open: open24, close: close24 });
        }
      }

      payload.hours[day] = intervals;
    });

    // Eliminado: captura de cierre temporal para este endpoint

    if (invalid) {
      knxToast('Invalid hub hours or closure info', 'error');
      return;
    }

    // Enviar al endpoint existente (knx/v1/save-hours)
    try {
      saveBtn.disabled = true;
      saveBtn.textContent = 'Saving...';

      const res = await fetch(`${knx_api.root}knx/v1/save-hours`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
      });

      const data = await res.json();

      if (data && data.success) {
        knxToast('✅ Working hours saved successfully!', 'success');
      } else {
        knxToast('❌ Failed to save working hours.', 'error');
      }
    } catch (err) {
      console.error(err);
      knxToast('⚠️ Network error while saving hours.', 'error');
    } finally {
      saveBtn.disabled = false;
      saveBtn.textContent = 'Save Working Hours';
    }
  });
});
