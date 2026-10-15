/* Админка China Car Importer */
(function ($) {
  'use strict';

  // Выбор файла для отчёта (медиатека)
  $(document).on('click', '.cci-pick-file', function (e) {
    e.preventDefault();
    var target = $($(this).data('target'));
    var frame = wp.media({ title: 'Файл отчёта', multiple: false, button: { text: 'Использовать' } });
    frame.on('select', function () {
      var a = frame.state().get('selection').first().toJSON();
      target.val(a.url);
    });
    frame.open();
  });

  // Форма автомобиля: тип двигателя
  var eng = $('#cci-engine');
  if (eng.length) {
    var toggle = function () { $('#cci-cc-wrap').toggle(eng.val() !== 'ev'); };
    eng.on('change', toggle); toggle();
  }

  // Форма автомобиля: фотографии
  var input = $('#cci-images');
  if (!input.length) { return; }
  var list = [];
  try { list = JSON.parse(input.val() || '[]'); } catch (e) { list = []; }

  function save() { input.val(JSON.stringify(list)); render(); }
  function render() {
    var wrap = $('#cci-photos').empty();
    if (!list.length) { wrap.append('<p class="cci-muted">Фото пока не добавлены.</p>'); return; }
    list.forEach(function (u, i) {
      var box = $('<div class="cci-ph">').append($('<img>').attr('src', u));
      if (i === 0) { box.append('<span class="cci-ph-main">Главное</span>'); }
      var btns = $('<div class="cci-ph-btns">');
      if (i > 0) {
        btns.append($('<button type="button" title="Сделать главным">★</button>').on('click', function () { list.unshift(list.splice(i, 1)[0]); save(); }));
      }
      btns.append($('<button type="button" title="Убрать">×</button>').on('click', function () { list.splice(i, 1); save(); }));
      wrap.append(box.append(btns));
    });
  }

  $('#cci-add-photos').on('click', function (e) {
    e.preventDefault();
    var frame = wp.media({ title: 'Фото автомобиля', multiple: true, library: { type: 'image' }, button: { text: 'Добавить' } });
    frame.on('select', function () {
      frame.state().get('selection').each(function (a) { var u = a.toJSON().url; if (u && list.indexOf(u) < 0) { list.push(u); } });
      save();
    });
    frame.open();
  });

  $('#cci-add-photo-url').on('click', function () {
    var f = $('#cci-photo-url'), u = $.trim(f.val());
    if (/^https?:\/\//i.test(u) && list.indexOf(u) < 0) { list.push(u); f.val(''); save(); }
  });

  render();
})(jQuery);
