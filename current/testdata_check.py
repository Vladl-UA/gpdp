#!/usr/bin/env python3
"""
Проверка партии тестовых данных ДО заливки.

    python3 current/testdata_check.py <файл.json>
    python3 current/testdata_check.py <файл.json> --fix

`--fix` чинит одну конкретную беду — неэкранированные кавычки внутри
строковых значений (`"ООО "Интер-Ойл""`), на которой спотыкаются
генераторы, и записывает результат рядом с суффиксом `_fixed`.
Остальное не чинится: если сгенерировано неверно, это надо видеть,
а не заминать.

Смысл существования: построчный отчёт загрузчика показывает, ЧТО
отвергнуто, но уже после того, как файл прочитан целиком. Здесь
ошибки видны до заливки и с указанием места.
"""
import json
import re
import sys
from collections import Counter
from pathlib import Path

HERE = Path(__file__).resolve().parent

SCHEMA = {
    'well': {'int_number', 'date_done', 'voc_mr', 'voc_enterprise', 'voc_subsoiluser',
             'dec_depth', 'dec_temp', 'dec_diam_col', 'dec_diam_prev_col',
             'dec_depth_prev_col', 'dec_depth_usc', 'voc_usc', 'voc_technolog',
             'voc_status', 'ltext_remark'},
    'cementing_stage': {'int_stage_number', 'dec_pressure_work', 'dec_pressure_stop'},
    'cementing_interval': {'dec_depth_from', 'dec_depth_to', 'voc_material', 'dec_amount',
                           'dec_volume_plan', 'dec_volume_fact', 'dec_density_plan',
                           'dec_density_fact'},
    'cementing_buffer': {'dec_volume', 'voc_composition', 'dec_weight'},
    'material_test': {'dec_water_cement_ratio', 'dec_spreadability', 'dec_temp_static',
                      'dec_temp_dynamic', 'dec_pressure', 'dec_thickening_time',
                      'data_setting_start', 'data_setting_end', 'dec_strength_bending',
                      'dec_strength_compress', 'dec_linear_expansion',
                      'dec_water_separation', 'dec_fluid_loss'},
    'akc_by_material': {'dec_partial_casing_m', 'dec_partial_rock_m', 'dec_partial_casing_pct',
                        'dec_partial_rock_pct', 'dec_rigid_casing_m', 'dec_rigid_rock_m'},
    'cementing_reper': {'dec_volume', 'dec_density'},
    'cementing_reper_component': {'voc_component', 'dec_amount'},
    'productive_horizon': {'data_formation', 'dec_depth_from', 'dec_depth_to',
                           'voc_fluid_type', 'bul_peretok'},
    'akc_by_horizon': {'dec_partial_casing_m', 'dec_partial_rock_m', 'dec_partial_casing_pct',
                       'dec_partial_rock_pct', 'dec_rigid_casing_m', 'dec_rigid_rock_m'},
}

BANNED = {'id', 'rel_main', 'active', 'calc_volume_deviation'}


def load_dictionaries():
    allowed = {}
    for name in ('import_dictionaries.json', 'testdata_dictionaries.json'):
        path = HERE / name
        if not path.exists():
            continue
        for table, records in json.loads(path.read_text(encoding='utf-8')).items():
            allowed.setdefault(table, set()).update(r['data_name'] for r in records)
    # voc_status заведён через конфигуратор, файла с ним нет
    allowed.setdefault('voc_status', set()).update({'Запланирована', 'В работе', 'Завершена'})
    return allowed


def repair_quotes(text):
    """
    Экранирует кавычки внутри строковых значений. Работает и на
    отформатированном JSON, и на однострочном — генераторы выдают и то
    и другое, а построчный разбор на минифицированном файле бесполезен.

    Приём: идём по тексту символ за символом. Внутри строки кавычка
    закрывает её, только если следующий значащий символ — структурный
    (`:` `,` `}` `]`). Иначе это кавычка внутри значения, и её надо
    экранировать. Ровно та беда, на которой спотыкаются генераторы:
    `"ООО "Интер-Ойл""`.
    """
    out = []
    inside = False
    i = 0
    while i < len(text):
        ch = text[i]
        if not inside:
            out.append(ch)
            if ch == '"':
                inside = True
            i += 1
            continue
        if ch == '\\' and i + 1 < len(text):
            out.append(text[i:i + 2])
            i += 2
            continue
        if ch == '"':
            j = i + 1
            while j < len(text) and text[j] in ' \t\r\n':
                j += 1
            if j >= len(text) or text[j] in ':,}]':
                out.append('"')
                inside = False
            else:
                out.append('\\"')
            i += 1
            continue
        out.append(ch)
        i += 1
    return ''.join(out)


def num(value):
    try:
        return float(value)
    except (TypeError, ValueError):
        return None


def check(path, do_fix):
    text = Path(path).read_text(encoding='utf-8')
    text = text.strip()
    if text.startswith('```'):
        text = re.sub(r'^```[a-z]*\n|\n```$', '', text)
        print('ЗАМЕЧАНИЕ: убрано markdown-ограждение вокруг JSON')

    try:
        data = json.loads(text)
    except json.JSONDecodeError as e:
        print(f'JSON невалиден: {e}')
        repaired = repair_quotes(text)
        try:
            data = json.loads(repaired)
        except json.JSONDecodeError as e2:
            print(f'починка кавычек не помогла: {e2}')
            return 1
        print('после экранирования кавычек — валиден')
        if do_fix:
            out = Path(path).with_name(Path(path).stem + '_fixed.json')
            out.write_text(repaired, encoding='utf-8')
            print(f'записано: {out}')
        else:
            print('запустите с --fix, чтобы записать исправленный файл')

    allowed = load_dictionaries()
    errors = []

    def walk(record, table, where):
        for key, value in record.items():
            place = f'{where}.{key}'
            if isinstance(value, list):
                if key not in SCHEMA:
                    errors.append(f'{place}: неизвестная дочерняя таблица')
                    continue
                for n, child in enumerate(value):
                    walk(child, key, f'{where}.{key}[{n}]')
                continue
            if key in BANNED or key.startswith('dep_'):
                errors.append(f'{place}: служебное или вычисляемое поле, его быть не должно')
                continue
            if key not in SCHEMA[table]:
                errors.append(f'{place}: неизвестное поле')
                continue
            if not isinstance(value, str):
                errors.append(f'{place}: значение не строка ({type(value).__name__})')
                continue
            if key.startswith('voc_') and value not in allowed.get(key, set()):
                errors.append(f'{place}: "{value}" нет в справочнике')
            if key.startswith('dec_') and ',' in value:
                errors.append(f'{place}: "{value}" — запятая вместо точки')

    wells = data.get('well')
    if not isinstance(wells, list):
        print('в корне нет массива "well"')
        return 1

    for well in wells:
        number = well.get('int_number', '?')
        where = f'скв.{number}'
        walk(well, 'well', where)

        status = well.get('voc_status')
        stages = well.get('cementing_stage', [])
        horizons = well.get('productive_horizon', [])

        if status == 'Запланирована' and stages:
            errors.append(f'{where}: запланированная, но ступени есть')
        if status in ('Запланирована', 'В работе'):
            if any('akc_by_horizon' in h for h in horizons):
                errors.append(f'{where}: незавершённая, но есть АКЦ по пластам')
            if any('akc_by_material' in iv for s in stages for iv in s.get('cementing_interval', [])):
                errors.append(f'{where}: незавершённая, но есть АКЦ по материалу')
        if status == 'Завершена':
            if not stages:
                errors.append(f'{where}: завершённая без ступеней')
            for s in stages:
                for iv in s.get('cementing_interval', []):
                    if len(iv.get('akc_by_material', [])) != 1:
                        errors.append(f'{where}: у интервала не один АКЦ по материалу')
            for h in horizons:
                if len(h.get('akc_by_horizon', [])) != 1:
                    errors.append(f'{where}: у горизонта не один АКЦ по пласту')

        for s in stages:
            work, stop = num(s.get('dec_pressure_work')), num(s.get('dec_pressure_stop'))
            if work is not None and stop is not None and stop <= work:
                errors.append(f'{where}: стоп-давление не выше рабочего')
            for iv in s.get('cementing_interval', []):
                if len(iv.get('material_test', [])) != 1:
                    errors.append(f'{where}: у интервала не одно испытание')
                a, b = num(iv.get('dec_depth_from')), num(iv.get('dec_depth_to'))
                if a is not None and b is not None:
                    if a <= b:
                        errors.append(f'{where}: интервал {a}–{b}, ожидалось от > до')
                    # 2026-07-20: проверки на протяжённость интервала НЕТ
                    # сознательно. Правило «200…900» было выведено из
                    # одной реальной скважины с двумя ступенями и делёными
                    # интервалами; у одноступенчатой скважины интервал
                    # идёт от забоя до УСЦ и законно бывает длиннее тысячи
                    # метров. Проверять надо не длину, а то, что имеет
                    # смысл всегда: порядок глубин, попадание в ствол и
                    # отсутствие перекрытий.
                    depth = num(well.get('dec_depth'))
                    if depth is not None and a > depth:
                        errors.append(f'{where}: интервал начинается на {a}, глубже забоя {depth}')
            spans = sorted(
                (num(iv.get('dec_depth_to')), num(iv.get('dec_depth_from')))
                for iv in s.get('cementing_interval', [])
                if num(iv.get('dec_depth_to')) is not None and num(iv.get('dec_depth_from')) is not None
            )
            for (lo1, hi1), (lo2, hi2) in zip(spans, spans[1:]):
                if lo2 < hi1:
                    errors.append(f'{where}: интервалы {hi1}–{lo1} и {hi2}–{lo2} перекрываются')

        for h in horizons:
            a, b = num(h.get('dec_depth_from')), num(h.get('dec_depth_to'))
            if a is not None and b is not None and a <= b:
                errors.append(f'{where}: горизонт {a}–{b}, ожидалось от > до')

        dc, dp = num(well.get('dec_diam_col')), num(well.get('dec_diam_prev_col'))
        if dc is not None and dp is not None and dp <= dc:
            errors.append(f'{where}: диаметр предыдущей колонны не больше текущей')

    numbers = [w.get('int_number') for w in wells]
    duplicates = [n for n, c in Counter(numbers).items() if c > 1]
    if duplicates:
        errors.append(f'повторяющиеся номера скважин: {duplicates}')

    print()
    if errors:
        print(f'ОШИБОК: {len(errors)}')
        for e in errors:
            print('  ', e)
    else:
        print('ОШИБОК НЕТ')

    intervals = [iv for w in wells for s in w.get('cementing_stage', [])
                 for iv in s.get('cementing_interval', [])]
    horizons = [h for w in wells for h in w.get('productive_horizon', [])]
    print()
    print(f'скважин {len(wells)}, номера {min(numbers)}…{max(numbers)}')
    print(f'ступеней {sum(len(w.get("cementing_stage", [])) for w in wells)}, '
          f'интервалов {len(intervals)}, '
          f'из них с двумя буферами {sum(1 for iv in intervals if len(iv.get("cementing_buffer", [])) == 2)}')
    print(f'реперов {sum(1 for w in wells for s in w.get("cementing_stage", []) if s.get("cementing_reper"))}')
    peretok = sum(1 for h in horizons if h.get('bul_peretok') == '1')
    print(f'горизонтов {len(horizons)}, из них с перетоком {peretok}'
          + (f' ({peretok * 100 // len(horizons)} %)' if horizons else ''))
    print('состояния:', dict(Counter(w.get('voc_status') for w in wells)))
    print('технологи:', dict(Counter(w.get('voc_technolog') for w in wells)))
    return 1 if errors else 0


if __name__ == '__main__':
    if len(sys.argv) < 2:
        print(__doc__)
        sys.exit(2)
    sys.exit(check(sys.argv[1], '--fix' in sys.argv))
