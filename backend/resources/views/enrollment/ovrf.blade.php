<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Official Verification / Validated Registration Form</title>
    <style>
        @page { margin: 22px 28px 30px; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #152b23; font-family: DejaVu Sans, sans-serif; font-size: 9px; line-height: 1.35; }
        .document { border: 1.5px solid #145d3d; padding: 16px 18px 18px; }
        .header { position: relative; min-height: 80px; border-bottom: 2px solid #145d3d; padding: 4px 90px 12px; text-align: center; }
        .seal { position: absolute; top: 0; left: 5px; width: 70px; height: 70px; object-fit: contain; }
        .institution { margin: 0 0 2px; font-size: 18px; font-weight: 700; letter-spacing: .3px; }
        .office { margin: 0; font-size: 10px; text-transform: uppercase; letter-spacing: 1px; }
        h1 { margin: 8px 0 2px; color: #145d3d; font-size: 15px; text-transform: uppercase; letter-spacing: .6px; }
        .subtitle { margin: 0; color: #52645d; }
        .notice { margin: 12px 0; border: 1px solid #d5a72e; background: #fff8df; padding: 8px 10px; color: #58460f; font-weight: 700; text-align: center; }
        .meta { width: 100%; margin: 8px 0 12px; border-collapse: collapse; }
        .meta td { width: 50%; border: 1px solid #b9c9c2; padding: 6px 8px; vertical-align: top; }
        .label { display: block; color: #5f7069; font-size: 7px; font-weight: 700; letter-spacing: .5px; text-transform: uppercase; }
        .value { display: block; margin-top: 2px; font-size: 10px; font-weight: 700; }
        h2 { margin: 12px 0 5px; color: #145d3d; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; }
        table.subjects { width: 100%; border-collapse: collapse; }
        .subjects th { background: #145d3d; color: white; font-size: 8px; text-align: left; }
        .subjects th, .subjects td { border: 1px solid #aebfb7; padding: 5px; vertical-align: top; }
        .subjects tbody tr:nth-child(even) { background: #f3f7f5; }
        .subjects .units { width: 42px; text-align: center; }
        .subjects .code { width: 58px; }
        .subjects .schedule { width: 155px; }
        .subjects tfoot th { background: #e0f1e8; color: #143b2c; text-align: right; }
        .subjects tfoot td { background: #e0f1e8; font-weight: 700; text-align: center; }
        .pending { color: #745d17; font-style: italic; }
        .finance { border: 1px solid #b9c9c2; background: #f7f9f8; padding: 7px 9px; }
        .signatures { width: 100%; margin-top: 28px; border-collapse: collapse; page-break-inside: avoid; }
        .signatures td { width: 33.333%; padding: 38px 12px 0; text-align: center; vertical-align: bottom; }
        .signature-line { border-top: 1px solid #24382f; padding-top: 5px; font-weight: 700; }
        .stamp-space { height: 72px; border: 1px dashed #71827b; padding-top: 26px; color: #63756d; font-weight: 700; text-transform: uppercase; }
        .validation { margin-top: 16px; border-top: 1px solid #b9c9c2; padding-top: 8px; color: #41544c; font-size: 8px; }
        .validation strong { color: #8c2d24; text-transform: uppercase; }
        .footer { margin-top: 9px; color: #63756d; font-size: 7px; text-align: center; }
    </style>
</head>
<body>
<main class="document">
    <header class="header">
        @if ($seal)<img class="seal" src="{{ $seal }}" alt="Colegio de Montalban seal">@endif
        <p class="institution">COLEGIO DE MONTALBAN</p>
        <p class="office">Registrar's Office · Rodriguez, Rizal</p>
        <h1>Official Verification / Validated Registration Form</h1>
        <p class="subtitle">Academic enrollment record for physical verification</p>
    </header>

    <div class="notice">This generated form is not yet officially validated. Present it to the Registrar's Office for physical verification, signatures, and the official school stamp/seal.</div>

    <table class="meta">
        <tr>
            <td><span class="label">Student number</span><span class="value">{{ $document['student_number'] ?: 'Unavailable' }}</span></td>
            <td><span class="label">Student name</span><span class="value">{{ $document['student_name'] ?: 'Unavailable' }}</span></td>
        </tr>
        <tr>
            <td><span class="label">Program</span><span class="value">{{ $document['program_code'] ?: 'Program' }} — {{ $document['program'] ?: 'Unavailable' }}</span></td>
            <td><span class="label">Year level / classification</span><span class="value">Year {{ $document['year_level'] ?: 'Unavailable' }} · {{ $document['classification'] ? ucfirst($document['classification']) : 'Unavailable' }}</span></td>
        </tr>
        <tr>
            <td><span class="label">Academic term</span><span class="value">{{ $document['academic_year'] }} · {{ $document['semester'] }}</span></td>
            <td><span class="label">Section / enrollment date</span><span class="value">{{ $document['section'] }} · {{ $document['enrollment_date'] }}</span></td>
        </tr>
    </table>

    <h2>Official subject load</h2>
    <table class="subjects">
        <thead><tr><th class="code">Code</th><th>Subject</th><th>Section</th><th class="schedule">Schedule / room</th><th>Professor</th><th class="units">Units</th></tr></thead>
        <tbody>
        @foreach ($document['subjects'] as $subject)
            <tr>
                <td>{{ $subject['code'] }}</td>
                <td>{{ $subject['title'] }}</td>
                <td>{{ $subject['section'] }}</td>
                <td>
                    @forelse ($subject['meetings'] as $meeting)
                        {{ $meeting['day'] }} {{ $meeting['start_time'] }}–{{ $meeting['end_time'] }} · {{ $meeting['room'] ?: 'Room pending' }}<br>
                    @empty
                        <span class="pending">To be announced — schedule pending</span>
                    @endforelse
                </td>
                <td>{{ $subject['professor'] ?: 'To be assigned' }}</td>
                <td class="units">{{ number_format($subject['units'], 1) }}</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot><tr><th colspan="5">Total units</th><td>{{ number_format($document['total_units'], 1) }}</td></tr></tfoot>
    </table>

    <h2>Verified financial information</h2>
    <div class="finance">{{ $document['financial_status'] }}</div>

    <table class="signatures">
        <tr>
            <td><div class="signature-line">Student signature over printed name</div></td>
            <td><div class="signature-line">Registrar signature over printed name</div></td>
            <td><div class="stamp-space">Official school stamp / seal</div></td>
        </tr>
    </table>

    <div class="validation"><strong>Physical validation required.</strong> Generating, downloading, or printing this form does not constitute Registrar approval and does not make it an officially validated document. No digital signature, scanned staff signature, or approval stamp has been embedded.</div>
    <div class="footer">Enrollment record #{{ $document['record_id'] }} · Generated {{ $document['generated_at']->format('Y-m-d H:i:s') }} · Subject schedules may remain pending after authorized enrollment finalization.</div>
</main>
</body>
</html>
