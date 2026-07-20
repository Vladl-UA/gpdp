/*
 * Общее поведение форм конфигуратора. 2026-07-20.
 *
 * До этого две формы — создание таблицы и правка таблицы — несли по
 * своей копии onFieldTypeChange() и onVocPick(). onVocPick совпадал
 * дословно; у onFieldTypeChange совпадало ядро (видимость выбора
 * словаря, цели ссылки и поля имени), а расходились хвосты: форма
 * создания сбрасывала видимость подписей, форма правки показывала
 * строку формулы для calc_ и лениво строила кнопки переменных. Копии
 * успели разойтись — ровно так, как и должны расходиться копии.
 *
 * Тот же ход, что со style.css 07-18 (CSS был вынесен из labels.php
 * в свой слой): поведение живёт в статике, кэшируется браузером
 * отдельно от HTML, данные страницы остаются в inline-скрипте.
 *
 * Крючков нет сознательно (§9 — механизм расширения вместо крючков):
 * страница не «доопределяет» общую функцию через магический
 * необязательный глобал, а объявляет свой onFieldTypeChange, который
 * ЯВНО зовёт общий помощник и дальше делает своё. Что происходит на
 * конкретной странице, видно в этой странице, а не выводится из
 * наличия функции с условленным именем.
 */

/**
 * Видимость полей строки в зависимости от выбранного типа. Общее для
 * обеих форм.
 *
 * voc_: имя выбирается из существующих словарей, а не печатается
 * (§16, уровень 0). link_/links_: имя СВОБОДНОЕ, как у обычного поля,
 * а цель выбирается отдельно (журнал 07-12: имя и адрес — разные
 * вещи, обе видны сразу; авто-заполнения подписи от цели нет, потому
 * что семантика поля «любимый цвет» не совпадает с подписью цели
 * «Цвет»).
 */
function configuratorFieldTypeVisibility(select) {
  const row  = select.closest('.field-row');
  const name = row.querySelector('.f-name');
  const voc  = row.querySelector('.f-voc-pick');
  const link = row.querySelector('.f-link-target');

  const isVoc  = select.value === 'voc';
  const isLink = select.value === 'link' || select.value === 'links';

  voc.style.display  = isVoc ? '' : 'none';
  link.style.display = isLink ? '' : 'none';
  name.style.display = isVoc ? 'none' : '';

  return row;
}

/**
 * Выбран словарь — подставить его подписи в короткую и полную.
 * Совпадал дословно в обеих формах.
 */
function configuratorVocPick(select, dictLabels) {
  const row  = select.closest('.field-row');
  const info = dictLabels[select.value];
  if (!info) {
    return;
  }
  row.querySelector('.f-short').value = info.short;
  row.querySelector('.f-full').value  = info.full;
}
