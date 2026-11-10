'use strict';

const menuButton = document.querySelector('.menu-toggle');
const menu = document.querySelector('.sidebar');
const menuClose = document.querySelector('.menu-close');
const mobileMenu = window.matchMedia('(max-width: 700px)');
const syncMenu = () => {
  const open = menu?.classList.contains('open') ?? false;
  menuButton?.setAttribute('aria-expanded', String(open));
  if (mobileMenu.matches && !open) menu?.setAttribute('inert', '');
  else menu?.removeAttribute('inert');
};
const closeMenu = () => { menu?.classList.remove('open'); syncMenu(); };
menuButton?.addEventListener('click', () => {
  menu.classList.toggle('open'); syncMenu();
});
menuClose?.addEventListener('click', () => { closeMenu(); menuButton?.focus(); });
mobileMenu.addEventListener('change', syncMenu);
syncMenu();
document.addEventListener('click', event => {
  if (menu?.classList.contains('open') && !menu.contains(event.target) && !menuButton.contains(event.target)) {
    closeMenu();
  }
});
document.addEventListener('keydown', event => {
  if (event.key === 'Escape' && menu?.classList.contains('open')) {
    closeMenu(); menuButton.focus();
  }
});

const dirtyForms = new Set();
for (const form of document.querySelectorAll('form[data-dirty]')) {
  const mark = () => { dirtyForms.add(form); };
  form.addEventListener('input', mark); form.addEventListener('change', mark);
}
const formError = document.querySelector('#form-error');
if (formError) {
  for (const form of document.querySelectorAll('form[data-dirty]')) {
    const action = form.querySelector('input[name="action"]')?.value;
    const kind = form.querySelector('input[name="tipo"]')?.value;
    if (action === formError.dataset.action && (!formError.dataset.kind || kind === formError.dataset.kind)) dirtyForms.add(form);
  }
}
window.addEventListener('beforeunload', event => {
  if (!dirtyForms.size) return;
  event.preventDefault(); event.returnValue = '';
});
for (const form of document.querySelectorAll('form[method="post"]')) {
  form.addEventListener('submit', event => {
    if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) { event.preventDefault(); return; }
    if ([...dirtyForms].some(other => other !== form) && !window.confirm('Hay cambios sin guardar en otro formulario de esta pantalla. ¿Continuar y descartarlos?')) { event.preventDefault(); return; }
    const submitter = event.submitter;
    // Keep the selected action even while disabling the clicked button.
    if (submitter?.name) {
      let field = form.querySelector(`input[type="hidden"][name="${CSS.escape(submitter.name)}"]`);
      if (!field) { field = document.createElement('input'); field.type = 'hidden'; field.name = submitter.name; form.append(field); }
      field.value = submitter.value;
      submitter.dataset.submitName = submitter.name;
      submitter.removeAttribute('name');
    }
    dirtyForms.clear();
    for (const button of form.querySelectorAll('button[type="submit"]')) button.disabled = true;
    if (submitter) { submitter.dataset.originalLabel = submitter.textContent; submitter.textContent = 'Procesando…'; }
    form.setAttribute('aria-busy', 'true');
  });
}
window.addEventListener('pageshow', event => {
  if (!event.persisted) return;
  for (const form of document.querySelectorAll('[aria-busy="true"]')) {
    form.removeAttribute('aria-busy');
    for (const button of form.querySelectorAll('button[type="submit"]')) button.disabled = false;
    for (const button of form.querySelectorAll('[data-original-label]')) button.textContent = button.dataset.originalLabel;
    for (const button of form.querySelectorAll('[data-submit-name]')) button.name = button.dataset.submitName;
  }
});

const delivery = document.querySelector('[data-delivery]');
if (delivery) {
  const body = delivery.querySelector('[data-lines]');
  const money = value => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(value) / 100);
  const parse = (value, digits) => {
    const pattern = new RegExp(`^\\d{1,9}(?:\\.\\d{1,${digits}})?$`);
    if (!pattern.test(value.trim())) return null;
    const [whole, part = ''] = value.trim().split('.');
    return BigInt(whole) * (10n ** BigInt(digits)) + BigInt(part.padEnd(digits, '0'));
  };
  const calculate = () => {
    let total = 0n;
    for (const [index, row] of [...body.querySelectorAll('[data-line]')].entries()) {
      row.querySelector('[name="producto[]"]').setAttribute('aria-label', `Producto del renglón ${index + 1}`);
      row.querySelector('[name="cantidad[]"]').setAttribute('aria-label', `Cantidad del renglón ${index + 1}`);
      row.querySelector('[name="precio[]"]').setAttribute('aria-label', `Precio del renglón ${index + 1}`);
      const quantity = parse(row.querySelector('[name="cantidad[]"]').value, 3);
      const price = parse(row.querySelector('[name="precio[]"]').value, 2);
      const amount = quantity === null || price === null ? null : (quantity * price + 500n) / 1000n;
      row.querySelector('[data-line-total]').textContent = amount === null ? '—' : money(amount);
      total += amount ?? 0n;
    }
    delivery.querySelector('[data-grand-total]').textContent = money(total);
    document.dispatchEvent(new Event('suave:fields-updated'));
  };
  delivery.addEventListener('input', calculate);
  delivery.querySelector('[data-add-line]').addEventListener('click', () => {
    if (body.children.length >= 100) { window.alert('Una nota admite hasta 100 renglones.'); return; }
    const row = body.querySelector('[data-line]').cloneNode(true);
    for (const field of row.querySelectorAll('input, select')) field.value = '';
    row.querySelector('[data-line-total]').textContent = '—';
    body.append(row); dirtyForms.add(delivery);
    row.querySelector('select').focus(); calculate();
  });
  body.addEventListener('click', event => {
    const remove = event.target.closest('[data-remove-line]');
    if (!remove) return;
    const row = remove.closest('[data-line]');
    if (body.children.length > 1) row.remove();
    else for (const field of row.querySelectorAll('input, select')) field.value = '';
    dirtyForms.add(delivery); calculate();
  });
  calculate();
}
const error = document.querySelector('#form-error');
if (error) error.focus({ preventScroll: true });

// Native controls keep their editing behavior; long values remain readable below.
const readableControls = 'select,input[type="text"],input[type="search"],input[type="file"]';
const textMeasure = document.createElement('canvas').getContext('2d');
const watchedControls = new WeakSet();
const tableHints = new WeakMap();
let tableHintNumber = 0;
const showFullValue = control => {
  if (!control.closest('.field') || !textMeasure) return;
  const text = control.tagName === 'SELECT'
    ? (control.value ? control.selectedOptions[0]?.textContent.trim() ?? '' : '')
    : (control.type === 'file' ? ([...control.files].map(file => file.name).join(', ') || 'Ningún archivo seleccionado.') : control.value);
  const style = getComputedStyle(control);
  textMeasure.font = style.font;
  const reserved = control.tagName === 'SELECT' ? 24 : (control.type === 'file' ? 165 : 0);
  const available = control.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight) - reserved;
  const needed = control.clientWidth > 0 && text !== '' && (control.type === 'file' || textMeasure.measureText(text).width > available);
  let note = control.nextElementSibling;
  if (!note?.classList.contains('field-value-full')) {
    if (!needed) return;
    note = document.createElement('small');
    note.className = 'field-value-full';
    note.setAttribute('aria-hidden', 'true');
    control.after(note);
  }
  note.textContent = text;
  note.hidden = !needed;
};
const showTableHint = table => {
  const needed = table.clientWidth > 0 && table.scrollWidth > table.clientWidth + 1;
  let hint = tableHints.get(table);
  if (!hint && needed) {
    hint = document.createElement('p');
    hint.className = 'table-scroll-hint';
    hint.id = 'table-scroll-' + (++tableHintNumber);
    hint.textContent = 'Desliza la tabla hacia los lados para ver todas las columnas.';
    table.before(hint);
    tableHints.set(table, hint);
  }
  if (hint) {
    hint.hidden = !needed;
    if (needed) {
      table.tabIndex = 0;
      table.setAttribute('role', 'region');
      table.setAttribute('aria-label', 'Tabla con desplazamiento horizontal');
      table.setAttribute('aria-describedby', hint.id);
    } else {
      table.removeAttribute('tabindex');
      table.removeAttribute('role');
      table.removeAttribute('aria-label');
      table.removeAttribute('aria-describedby');
    }
  }
};
const responsiveObserver = new ResizeObserver(entries => {
  for (const { target } of entries) {
    if (target.matches('.table-wrap')) showTableHint(target);
    else showFullValue(target);
  }
});
const watchResponsiveContent = root => {
  for (const control of root.querySelectorAll(readableControls)) {
    if (!watchedControls.has(control)) {
      watchedControls.add(control);
      responsiveObserver.observe(control);
    }
    showFullValue(control);
  }
  for (const table of root.querySelectorAll('.table-wrap')) {
    responsiveObserver.observe(table);
    showTableHint(table);
  }
};
watchResponsiveContent(document);
for (const eventName of ['input', 'change']) document.addEventListener(eventName, event => {
  if (event.target.matches(readableControls)) showFullValue(event.target);
});
document.addEventListener('suave:fields-updated', () => {
  document.querySelectorAll(readableControls).forEach(showFullValue);
});
new MutationObserver(records => {
  for (const record of records) {
    for (const node of record.addedNodes) {
      if (!(node instanceof Element)) continue;
      if (node.matches(readableControls)) { responsiveObserver.observe(node); showFullValue(node); }
      watchResponsiveContent(node);
    }
    for (const node of record.removedNodes) {
      if (!(node instanceof Element)) continue;
      if (node.matches(readableControls + ',.table-wrap')) responsiveObserver.unobserve(node);
      node.querySelectorAll(readableControls + ',.table-wrap').forEach(element => responsiveObserver.unobserve(element));
    }
  }
}).observe(document.querySelector('main'), { childList: true, subtree: true });
