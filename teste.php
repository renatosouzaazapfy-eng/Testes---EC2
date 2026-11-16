<?php




declare(strict_types=1);

function consultarApi(string $url): array
{
    $curl = curl_init($url);

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
        ],
    ]);

    $resposta = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    if ($resposta === false) {
        $erro = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException('Falha na conexão: ' . $erro);
    }

    curl_close($curl);

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("A API retornou HTTP $status");
    }

    $dados = json_decode($resposta, true);

    if (!is_array($dados)) {
        throw new RuntimeException('Resposta JSON inválida.');
    }

    return $dados;
}

function consultarClima(string $cidade): array
{
    $urlGeocoding = 'https://geocoding-api.open-meteo.com/v1/search?'
        . http_build_query([
            'name' => $cidade,
            'count' => 10,
            'language' => 'pt',
            'format' => 'json',
        ]);

    $geocoding = consultarApi($urlGeocoding);

    if (empty($geocoding['results'])) {
        throw new RuntimeException('Cidade não encontrada: ' . $cidade);
    }

    $local = $geocoding['results'][0];

    $urlClima = 'https://api.open-meteo.com/v1/forecast?'
        . http_build_query([
            'latitude' => $local['latitude'],
            'longitude' => $local['longitude'],
            'current' => implode(',', [
                'temperature_2m',
                'relative_humidity_2m',
                'apparent_temperature',
                'precipitation',
                'wind_speed_10m',
                'weather_code',
            ]),
            'daily' => implode(',', [
                'temperature_2m_max',
                'temperature_2m_min',
                'precipitation_probability_max',
                'weather_code',
            ]),
            'timezone' => 'auto',
            'wind_speed_unit' => 'kmh',
            'forecast_days' => 3,
        ]);

    $clima = consultarApi($urlClima);

    if (!isset($clima['current'], $clima['daily'])) {
        throw new RuntimeException('Dados meteorológicos incompletos.');
    }

    $atual = $clima['current'];
    $diario = $clima['daily'];
    $previsao = [];

    foreach ($diario['time'] ?? [] as $indice => $data) {
        $previsao[] = [
            'data' => $data,
            'maxima' => $diario['temperature_2m_max'][$indice] ?? 0,
            'minima' => $diario['temperature_2m_min'][$indice] ?? 0,
            'chance_chuva' => $diario['precipitation_probability_max'][$indice] ?? 0,
            'codigo_tempo' => $diario['weather_code'][$indice] ?? 0,
        ];
    }

    return [
        'cidade' => $local['name'],
        'regiao' => $local['admin1'] ?? 'Não informada',
        'pais' => $local['country'] ?? 'Não informado',
        'temperatura' => $atual['temperature_2m'] ?? 0,
        'sensacao' => $atual['apparent_temperature'] ?? 0,
        'umidade' => $atual['relative_humidity_2m'] ?? 0,
        'vento' => $atual['wind_speed_10m'] ?? 0,
        'precipitacao' => $atual['precipitation'] ?? 0,
        'codigo_tempo' => $atual['weather_code'] ?? 0,
        'previsao' => $previsao,
    ];
}

function descreverTempo(int $codigo): array
{
    return match ($codigo) {
        0 => ['Céu limpo', '☀️'],
        1 => ['Predominantemente limpo', '🌤️'],
        2 => ['Parcialmente nublado', '⛅'],
        3 => ['Nublado', '☁️'],
        45, 48 => ['Neblina', '🌫️'],
        51, 53, 55 => ['Garoa', '🌦️'],
        56, 57 => ['Garoa congelante', '🌧️'],
        61, 63, 65 => ['Chuva', '🌧️'],
        66, 67 => ['Chuva congelante', '🌧️'],
        71, 73, 75, 77 => ['Neve', '❄️'],
        80, 81, 82 => ['Pancadas de chuva', '🌧️'],
        85, 86 => ['Pancadas de neve', '🌨️'],
        95, 96, 99 => ['Trovoadas', '⛈️'],
        default => ['Condição indisponível', '🌡️'],
    };
}

function escapar(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$modoTerminal = PHP_SAPI === 'cli';
$cidade = trim((string) ($modoTerminal
    ? ($argv[1] ?? 'Belo Horizonte')
    : ($_GET['cidade'] ?? '')));
$dados = null;
$erro = null;

if ($modoTerminal || $cidade !== '') {
    try {
        if ($cidade === '') {
            throw new InvalidArgumentException('Informe o nome de uma cidade.');
        }

        $dados = consultarClima($cidade);
    } catch (Throwable $e) {
        if ($modoTerminal) {
            fwrite(STDERR, 'ERRO: ' . $e->getMessage() . PHP_EOL);
            exit(1);
        }

        $erro = $e->getMessage();
    }
}

if ($modoTerminal) {
    $tempoAtual = descreverTempo((int) $dados['codigo_tempo']);

    echo PHP_EOL . "==================================" . PHP_EOL;
    echo "       WEATHERCLI - RELATÓRIO     " . PHP_EOL;
    echo "==================================" . PHP_EOL;
    echo "Local: {$dados['cidade']}, {$dados['regiao']} - {$dados['pais']}" . PHP_EOL;
    echo "Condição: {$tempoAtual[0]}" . PHP_EOL;
    printf("Temperatura: %.1f °C\n", $dados['temperatura']);
    printf("Sensação térmica: %.1f °C\n", $dados['sensacao']);
    printf("Umidade: %.0f%%\n", $dados['umidade']);
    printf("Vento: %.1f km/h\n", $dados['vento']);
    printf("Precipitação atual: %.1f mm\n", $dados['precipitacao']);

    foreach ($dados['previsao'] as $dia) {
        printf(
            "%s: mínima %.1f °C, máxima %.1f °C, chuva %.0f%%\n",
            $dia['data'],
            $dia['minima'],
            $dia['maxima'],
            $dia['chance_chuva']
        );
    }

    exit(0);
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#101828">
    <title>Clima agora</title>
    <style>
        :root {
            color-scheme: dark;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #101828;
            color: #f8fafc;
            font-synthesis: none;
        }

        * { box-sizing: border-box; }

        body {
            min-height: 100vh;
            margin: 0;
            padding: 48px 20px;
            background:
                radial-gradient(ellipse at 15% 10%, rgba(56, 189, 248, .14), transparent 35%),
                radial-gradient(ellipse at 90% 80%, rgba(129, 140, 248, .12), transparent 35%),
                #101828;
        }

        main { width: min(100%, 820px); margin: 0 auto; }
        .eyebrow { color: #7dd3fc; font-size: .78rem; font-weight: 700; letter-spacing: .16em; text-transform: uppercase; }
        h1 { margin: 10px 0 8px; font-size: clamp(2rem, 6vw, 3.2rem); letter-spacing: -.05em; }
        .intro { margin: 0; color: #aab7c8; }

        form {
            display: flex;
            gap: 10px;
            margin: 30px 0 24px;
            padding: 8px;
            border: 1px solid #344054;
            border-radius: 16px;
            background: rgba(16, 24, 40, .78);
        }

        input {
            flex: 1;
            min-width: 0;
            padding: 12px 14px;
            border: 0;
            outline: 0;
            background: transparent;
            color: #f8fafc;
            font: inherit;
        }

        input::placeholder { color: #8492a6; }
        button {
            padding: 12px 20px;
            border: 0;
            border-radius: 10px;
            background: #38bdf8;
            color: #082f49;
            cursor: pointer;
            font: inherit;
            font-weight: 750;
        }

        button:hover { background: #7dd3fc; }
        .panel { border: 1px solid #344054; border-radius: 20px; background: rgba(25, 36, 56, .86); }
        .error { padding: 16px 18px; border-color: #7f1d1d; background: rgba(127, 29, 29, .2); color: #fecaca; }
        .empty { padding: 36px 24px; color: #aab7c8; text-align: center; }
        .weather { padding: clamp(22px, 5vw, 36px); }
        .location { margin: 0; font-size: 1.2rem; }
        .location span { color: #aab7c8; font-size: .95rem; font-weight: 400; }
        .current { display: flex; align-items: center; gap: 20px; padding: 24px 0 28px; }
        .weather-icon { font-size: 4rem; line-height: 1; }
        .temperature { font-size: clamp(3.5rem, 12vw, 5rem); font-weight: 650; letter-spacing: -.08em; line-height: 1; }
        .temperature sup { margin-left: 3px; font-size: .38em; vertical-align: top; }
        .condition { margin: 9px 0 0; color: #aab7c8; }
        .section-title { margin: 0 0 14px; color: #aab7c8; font-size: .78rem; letter-spacing: .12em; text-transform: uppercase; }
        .details { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
        .detail, .forecast-day { padding: 16px; border-radius: 14px; background: rgba(255, 255, 255, .045); }
        .detail-label { display: block; margin-bottom: 8px; color: #aab7c8; font-size: .8rem; }
        .detail-value { font-size: 1.05rem; font-weight: 650; }
        .forecast { margin-top: 28px; }
        .forecast-list { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
        .forecast-day { text-align: center; }
        .forecast-date { color: #aab7c8; font-size: .83rem; }
        .forecast-icon { margin: 10px 0; font-size: 1.8rem; }
        .forecast-temps { font-weight: 650; }
        .forecast-rain { margin-top: 7px; color: #7dd3fc; font-size: .82rem; }
        footer { margin-top: 18px; color: #718096; font-size: .78rem; text-align: center; }

        @media (max-width: 600px) {
            body { padding: 34px 16px; }
            form { gap: 4px; }
            button { padding-inline: 14px; }
            .details { grid-template-columns: repeat(2, 1fr); }
            .forecast-list { grid-template-columns: 1fr; }
            .forecast-day { display: grid; grid-template-columns: 1fr auto 1fr; align-items: center; gap: 10px; text-align: left; }
            .forecast-icon { margin: 0; }
            .forecast-rain { grid-column: 1 / -1; margin-top: 0; }
        }
    </style>
</head>
<body>
<main>
    <header>
        <div class="eyebrow">WeatherCLI · Open-Meteo</div>
        <h1>Clima agora</h1>
        <p class="intro">Consulte as condições atuais e a previsão para os próximos dias.</p>
    </header>

    <form method="get">
        <input
            type="search"
            name="cidade"
            placeholder="Digite uma cidade, ex.: Belo Horizonte"
            value="<?= escapar($cidade) ?>"
            aria-label="Nome da cidade"
            required
        >
        <button type="submit">Buscar</button>
    </form>

    <?php if ($erro !== null): ?>
        <div class="panel error" role="alert"><?= escapar($erro) ?></div>
    <?php elseif ($dados === null): ?>
        <div class="panel empty">Digite o nome de uma cidade para ver a previsão do tempo.</div>
    <?php else: ?>
        <?php $tempoAtual = descreverTempo((int) $dados['codigo_tempo']); ?>
        <section class="panel weather" aria-label="Condições meteorológicas">
            <h2 class="location">
                <?= escapar($dados['cidade']) ?>
                <span><?= escapar($dados['regiao']) ?>, <?= escapar($dados['pais']) ?></span>
            </h2>

            <div class="current">
                <div class="weather-icon" aria-hidden="true"><?= $tempoAtual[1] ?></div>
                <div>
                    <div class="temperature"><?= number_format((float) $dados['temperatura'], 0) ?><sup>°C</sup></div>
                    <p class="condition"><?= escapar($tempoAtual[0]) ?></p>
                </div>
            </div>

            <h3 class="section-title">Agora</h3>
            <div class="details">
                <div class="detail">
                    <span class="detail-label">Sensação térmica</span>
                    <span class="detail-value"><?= number_format((float) $dados['sensacao'], 1) ?> °C</span>
                </div>
                <div class="detail">
                    <span class="detail-label">Umidade</span>
                    <span class="detail-value"><?= number_format((float) $dados['umidade'], 0) ?>%</span>
                </div>
                <div class="detail">
                    <span class="detail-label">Vento</span>
                    <span class="detail-value"><?= number_format((float) $dados['vento'], 1) ?> km/h</span>
                </div>
                <div class="detail">
                    <span class="detail-label">Precipitação atual</span>
                    <span class="detail-value"><?= number_format((float) $dados['precipitacao'], 1) ?> mm</span>
                </div>
            </div>

            <div class="forecast">
                <h3 class="section-title">Previsão para 3 dias</h3>
                <div class="forecast-list">
                    <?php foreach ($dados['previsao'] as $indice => $dia): ?>
                        <?php $tempoDia = descreverTempo((int) $dia['codigo_tempo']); ?>
                        <article class="forecast-day">
                            <div class="forecast-date">
                                <?= $indice === 0 ? 'Hoje' : escapar(substr($dia['data'], 8, 2) . '/' . substr($dia['data'], 5, 2)) ?>
                            </div>
                            <div class="forecast-icon" aria-hidden="true"><?= $tempoDia[1] ?></div>
                            <div class="forecast-temps">
                                <?= number_format((float) $dia['maxima'], 0) ?>° /
                                <?= number_format((float) $dia['minima'], 0) ?>°
                            </div>
                            <div class="forecast-rain">Chuva: <?= number_format((float) $dia['chance_chuva'], 0) ?>%</div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <footer>Dados meteorológicos fornecidos por Open-Meteo.</footer>
</main>
</body>
</html>
