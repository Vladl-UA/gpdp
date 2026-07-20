import json, random
random.seed(20260720)

MR = ["Мамуринская","Приобская","Самотлорская","Ватьеганская","Тевлинско-Русскинская","Южно-Ягунская","Повховская"]
ENT = ['ООО "Интер-Ойл"','ООО "Бурнефтесервис"','АО "Сибирская буровая компания"','ООО "ГеоБурСервис"','ООО "Западбурнефть"']
SUB = ['ЗАО "Самара-Нафта"','АО "Приобскнефть"','ПАО "Севернефтегаз"','ООО "Ватьеганнефть"']
TECH = ["Назаров","Ковалёв","Ерохин","Шаймиев","Дорошенко","Бельский"]
# перекос намеренный: у одних много скважин, у других одна
TECH_W = [5, 4, 3, 2, 1, 1]
MAT = ["ОТМ-3","ОТМ-5","РТМ-75","РТМ-75 ПВ","ПЦТ I-G-CC-1","ПЦТ III-Об 5-100","ОТМ-7","РТМ-100","ЦТРО-1"]
MAT_W = [6, 5, 5, 4, 3, 2, 2, 1, 1]
COMP = ["МБП-С","МБП-М","НТФ","МБП-СМ","Буфер водный","КССБ"]
RCOMP = ["ПЦТ-G-CC-1","КМЦ","Бентонит","НТФ","CFL-110","ПЦТ III-Об","Полицем","Гипан","Сульфацелл"]
FLUID = ["нефть+вода","нефть+газ","нефть"]
USC = {140:"УСЦ-140", 146:"МСЦ-146", 168:"МСЦ-168", 178:"МСЦ-178"}
REMARKS = ["Работы выполнены без замечаний.","Осложнение при спуске, потеря циркуляции на 1200 м.",
           "Заменён буферный состав по решению технолога.","Отклонение по плотности в пределах допуска.",
           "Простой 6 часов по погоде.","Повторный замер АКЦ по нижнему интервалу."]

def d(x, n=2): return f"{round(x, n)}"
def i(x): return str(int(round(x)))

def make_test():
    st = random.uniform(50, 90); dyn = random.uniform(45, min(70, st - 3))
    bend = random.uniform(1.0, 8.0)
    h1, m1 = random.randint(1, 6), random.randint(0, 59)
    add = random.randint(20, 180); h2, m2 = divmod(h1 * 60 + m1 + add, 60)
    t = {
        "dec_water_cement_ratio": d(random.uniform(0.38, 0.90)),
        "dec_spreadability": i(random.uniform(200, 260)),
        "dec_temp_static": i(st), "dec_temp_dynamic": i(dyn),
        "dec_pressure": i(random.uniform(250, 450)),
        "dec_thickening_time": i(random.uniform(90, 260)),
        "data_setting_start": f"{h1}-{m1:02d}", "data_setting_end": f"{h2}-{m2:02d}",
        "dec_strength_bending": d(bend, 1),
        "dec_strength_compress": d(random.uniform(bend + 1.5, 14.0), 1),
        "dec_water_separation": d(random.uniform(0.2, 3.5), 1),
        "dec_fluid_loss": i(random.uniform(60, 3000)),
    }
    if random.random() < 0.5:
        t["dec_linear_expansion"] = d(random.uniform(5, 20), 1)
    return t

def make_akc():
    return {
        "dec_partial_casing_m": i(random.uniform(1, 500)),
        "dec_partial_rock_m": i(random.uniform(1, 500)),
        "dec_partial_casing_pct": d(random.uniform(10, 90), 1),
        "dec_partial_rock_pct": d(random.uniform(10, 90), 1),
        "dec_rigid_casing_m": i(random.uniform(20, 600)),
        "dec_rigid_rock_m": i(random.uniform(20, 600)),
    }

def make_well(num, status, bad_well):
    depth = random.uniform(1800, 4200)
    diam = random.choice([140, 146, 168, 178])
    prev_diam = random.choice([v for v in (219, 245, 273, 324) if v > diam])
    year = {"Завершена": random.randint(2019, 2024), "В работе": 2025, "Запланирована": 2026}[status]
    w = {
        "int_number": str(num),
        "date_done": f"{year}-{random.randint(1,12):02d}-{random.randint(1,28):02d}",
        "voc_mr": random.choice(MR),
        "voc_enterprise": random.choice(ENT),
        "voc_subsoiluser": random.choice(SUB),
        "dec_depth": i(depth),
        "dec_temp": i(40 + depth / 4200 * 65),
        "dec_diam_col": str(diam),
        "dec_diam_prev_col": str(prev_diam),
        "dec_depth_prev_col": i(random.uniform(900, min(2400, depth * 0.7))),
        "dec_depth_usc": i(depth * random.uniform(0.55, 0.80)),
        "voc_usc": USC[diam],
        "voc_technolog": random.choices(TECH, weights=TECH_W)[0],
        "voc_status": status,
    }
    if random.random() < 0.33:
        w["ltext_remark"] = random.choice(REMARKS)

    # горизонты есть при любом состоянии
    horizons = []
    top = depth - random.uniform(50, 250)
    for _ in range(random.randint(2, 5)):
        h_from = top; h_to = h_from - random.uniform(10, 60)
        h = {
            "data_formation": random.choice(["АС","БС","ЮС"]) + str(random.randint(8, 12))
                              + (f"({random.randint(1,3)})" if random.random() < 0.4 else ""),
            "dec_depth_from": i(h_from), "dec_depth_to": i(h_to),
            "voc_fluid_type": random.choice(FLUID),
            "bul_peretok": "1" if (bad_well and random.random() < 0.6) else "0",
        }
        if status == "Завершена":
            h["akc_by_horizon"] = [make_akc()]
        horizons.append(h)
        top = h_to - random.uniform(60, 300)
        if top < 400: break
    w["productive_horizon"] = horizons

    if status == "Запланирована":
        return w

    # ступень 1 — самая глубокая, дальше к устью
    stages = []
    cursor = depth - random.uniform(0, 120)
    for sn in range(1, random.randint(1, 3) + 1):
        work = random.uniform(8, 20)
        st = {"int_stage_number": str(sn),
              "dec_pressure_work": d(work, 1),
              "dec_pressure_stop": d(work + random.uniform(3, 5), 1)}
        ivs = []
        for _ in range(random.randint(1, 3)):
            f_ = cursor; t_ = f_ - random.uniform(200, 900)
            if t_ < 200: break
            vp = random.uniform(5, 35); dp = random.uniform(1.15, 1.95)
            iv = {"dec_depth_from": i(f_), "dec_depth_to": i(t_),
                  "voc_material": random.choices(MAT, weights=MAT_W)[0],
                  "dec_amount": d(random.uniform(8, 30), 1),
                  "dec_volume_plan": d(vp), "dec_volume_fact": d(vp * random.uniform(0.92, 1.08)),
                  "dec_density_plan": d(dp), "dec_density_fact": d(dp + random.uniform(-0.03, 0.03))}
            bufs = []
            for _b in range(2 if random.random() < 0.2 else 1):
                bufs.append({"dec_volume": d(random.uniform(1, 5), 1),
                             "voc_composition": random.choice(COMP),
                             "dec_weight": i(random.uniform(10, 200))})
            iv["cementing_buffer"] = bufs
            iv["material_test"] = [make_test()]
            if status == "Завершена":
                iv["akc_by_material"] = [make_akc()]
            ivs.append(iv)
            cursor = t_
        if not ivs: break
        st["cementing_interval"] = ivs
        if random.random() < 0.5:
            comps = []
            for k in range(random.randint(2, 4)):
                c = {"voc_component": random.choice(RCOMP)}
                if k > 0:
                    c["dec_amount"] = d(random.uniform(0.01, 2.0))
                comps.append(c)
            st["cementing_reper"] = [{"dec_volume": d(random.uniform(2, 5), 1),
                                      "dec_density": d(random.uniform(1.30, 1.60)),
                                      "cementing_reper_component": comps}]
        stages.append(st)
    w["cementing_stage"] = stages
    return w

statuses = ["Завершена"] * 7 + ["В работе"] * 2 + ["Запланирована"]
bad = {103, 107}
wells = [make_well(101 + k, statuses[k], (101 + k) in bad) for k in range(10)]
json.dump({"well": wells}, open('/home/claude/gen/testdata_wells_101_110.json', 'w', encoding='utf-8'),
          ensure_ascii=False, indent=2)
print("сгенерировано:", len(wells))
