<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Official Registration Form</title>
    <style>
        @page { margin: 0; }
        body { margin: 0; color: #26382e; font-family: DejaVu Sans, sans-serif; font-size: 6.5pt; line-height: 1.25; }
        .sheet { width: 488pt; min-height: 704pt; margin: 32pt 0 0 50pt; border: .8pt solid #26382e; padding: 11pt; }
        .header { position: relative; height: 78pt; }
        .seal { position: absolute; top: 21pt; left: 8pt; width: 54pt; height: 54pt; }
        .school { margin-left: 76pt; padding-top: 13pt; width: 296pt; }
        .school-name { margin: 0 0 3pt; font-size: 13pt; font-weight: bold; letter-spacing: .3pt; }
        .school-place { margin: 0 0 6pt; color: #53665b; font-size: 7pt; }
        .school-office { margin: 0; font-size: 9pt; font-weight: bold; letter-spacing: .3pt; text-transform: uppercase; }
        .form-title { position: absolute; top: 20pt; right: 0; width: 91pt; font-size: 8pt; font-weight: bold; line-height: 1.35; text-align: right; text-transform: uppercase; }
        .term { height: 15pt; border: .5pt solid #6caa8c; background: #f4f8f5; padding: 5pt 7pt 0; font-size: 7.5pt; font-weight: bold; }
        .term-value { display: inline-block; min-width: 165pt; margin-left: 12pt; border-bottom: .5pt solid #8a9e91; font-weight: normal; text-align: center; }
        table { border-collapse: collapse; table-layout: fixed; width: 100%; }
        .section-heading { height: 14pt; border: .5pt solid #80ad98; background: #edf4f0; color: #277a5e; font-size: 8pt; font-weight: bold; letter-spacing: .2pt; text-align: left; text-transform: uppercase; padding: 4pt 7pt 0; }
        .student-heading { margin-top: 13pt; }
        .student-info td { height: 18pt; border: .5pt solid #a0c1ae; padding: 2pt 5pt 1pt; vertical-align: top; }
        .student-info .field-name { display: block; color: #59695f; font-size: 6pt; font-weight: bold; }
        .student-info .field-value { display: block; overflow-wrap: anywhere; font-size: 6.9pt; }
        .courses-heading { height: 16pt; margin-top: 13pt; padding-top: 5pt; text-align: center; }
        .courses th, .courses td { border: .5pt solid #a0c1ae; }
        .courses .column-head th { height: 21pt; background: #e3efe8; font-size: 6.5pt; line-height: 1.1; text-align: center; }
        .courses tbody td { height: 14pt; padding: 1.5pt 3pt; overflow-wrap: anywhere; vertical-align: middle; }
        .courses tbody tr:nth-child(even) td { background: #fbfdfb; }
        .courses .center { text-align: center; }
        .courses .total td { height: 16pt; background: #f4f8f5; font-size: 7pt; font-weight: bold; text-align: right; }
        .lower { margin-top: 13pt; page-break-inside: avoid; }
        .lower.continued { page-break-before: always; padding-top: 32pt; }
        .left-column { float: left; width: 235pt; }
        .right-column { float: right; width: 240pt; }
        .fees th, .payment th, .pledge-title { height: 16pt; border: .5pt solid #80ad98; background: #edf4f0; color: #277a5e; font-size: 7.5pt; font-weight: bold; text-align: left; text-transform: uppercase; }
        .fees th, .payment th { padding-left: 6pt; }
        .fees td { height: 9pt; border: .5pt solid #a0c1ae; padding: 1pt 5pt; font-size: 6pt; }
        .fees .fees-total td { border-top: .7pt solid #277a5e; font-weight: bold; }
        .fees .fees-total span { float: right; color: #59695f; font-weight: normal; }
        .pledge-title { padding: 4pt 6pt 0; }
        .pledge-body { height: 69pt; border: .5pt solid #a0c1ae; border-top: 0; }
        .pledge-copy { padding: 5pt 7pt; font-size: 5.8pt; line-height: 1.35; }
        .pledge-copy strong { display: block; margin-bottom: 4pt; color: #795b19; font-size: 5.7pt; }
        .pledge-copy p { margin: 0; }
        .signature { margin: 48pt 23pt 0; text-align: center; }
        .signature-line { border-top: .5pt solid #26382e; padding-top: 5pt; font-size: 6.5pt; }
        .signature.registrar { margin-top: 45pt; }
        .stamp { margin: 12pt 23pt 0; color: #68776d; font-size: 6pt; text-align: center; }
        .payment { margin-top: 14pt; }
        .payment td { height: 12pt; border: .5pt solid #a0c1ae; padding: 2pt 5pt; font-size: 6.5pt; }
        .footer { clear: both; padding-top: 5pt; font-size: 5.8pt; }
        .footer strong { color: #277a5e; }
        .footer .generated { float: right; }
        .validation { clear: both; margin-top: 5pt; color: #53665b; font-size: 5.3pt; }
    </style>
</head>
<body>
<main class="sheet">
    <header class="header">
        @if ($seal)<img class="seal" src="{{ $seal }}" alt="Colegio de Montalban seal">@endif
        <div class="school">
            <p class="school-name">COLEGIO DE MONTALBAN</p>
            <p class="school-place">Rodriguez, Rizal</p>
            <p class="school-office">Office of the Registrar</p>
        </div>
        <div class="form-title">Official<br>Registration<br>Form</div>
    </header>

    <div class="term">Academic Year / Term:<span class="term-value">{{ $document['academic_year'] }} · {{ $document['semester'] }}</span></div>

    <div class="section-heading student-heading">Student General Information</div>
    <table class="student-info">
        <tbody>
            <tr>
                <td style="width:39%"><span class="field-name">Student ID</span><span class="field-value">{{ $document['student_number'] }}</span></td>
                <td style="width:38%"><span class="field-name">Program</span><span class="field-value">{{ $document['program_code'] }}{{ $document['program'] ? ' — '.$document['program'] : '' }}</span></td>
                <td style="width:23%"><span class="field-name">Student Type</span><span class="field-value">{{ $document['classification'] ? ucfirst($document['classification']) : '' }}</span></td>
            </tr>
            <tr>
                <td><span class="field-name">Student Name</span><span class="field-value">{{ $document['student_name'] }}</span></td>
                <td><span class="field-name">Institute</span><span class="field-value">{{ $document['institute'] }}</span></td>
                <td><span class="field-name">Year Level</span><span class="field-value">{{ $document['year_level'] }}</span></td>
            </tr>
        </tbody>
    </table>

    <div class="section-heading courses-heading">Courses Enrolled</div>
    <table class="courses">
        <thead>
            <tr class="column-head"><th style="width:10%">Code</th><th style="width:34%">Description</th><th style="width:10%">Section</th><th style="width:6%">Lec</th><th style="width:6%">Lab</th><th style="width:8%">Comp<br>Lab</th><th style="width:7%">Units</th><th style="width:7%">Rate</th><th style="width:12%">Course<br>Fees</th></tr>
        </thead>
        <tbody>
            @foreach ($document['subjects'] as $subject)
                <tr>
                    <td>{{ $subject['code'] }}</td><td>{{ $subject['title'] }}</td><td class="center">{{ $subject['section'] }}</td>
                    <td class="center">{{ $subject['lecture_hours'] }}</td><td class="center">{{ $subject['laboratory_hours'] }}</td><td></td>
                    <td class="center">{{ number_format($subject['units'], 1) }}</td><td></td><td></td>
                </tr>
            @endforeach
            @for ($row = count($document['subjects']); $row < 9; $row++)
                <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
            @endfor
            <tr class="total"><td colspan="9">Total Units: {{ number_format($document['total_units'], 1) }}</td></tr>
        </tbody>
    </table>

    <div class="lower{{ count($document['subjects']) > 9 ? ' continued' : '' }}">
        <div class="left-column">
            <table class="fees">
                <colgroup><col style="width:70%"><col style="width:30%"></colgroup>
                <thead><tr><th colspan="2">Assessed Fees</th></tr></thead>
                <tbody>
                    @foreach (['Tuition Fee', 'Admission Fee', 'Athletic Fee', 'Computer Fee', 'Cultural Fee', 'Development Fee', 'Guidance Fee', 'Laboratory Fee', 'Library Fee', 'Medical / Dental Fee', 'Registration Fee', 'Other Approved Fees'] as $fee)
                        <tr><td>{{ $fee }}</td><td></td></tr>
                    @endforeach
                    <tr class="fees-total"><td colspan="2">Total Assessed Amount <span>Not yet assessed</span></td></tr>
                </tbody>
            </table>
            <table class="payment">
                <colgroup><col style="width:45%"><col style="width:55%"></colgroup>
                <thead><tr><th colspan="2">Payment Details</th></tr></thead>
                <tbody>
                    <tr><td>Mode of Payment</td><td></td></tr>
                    <tr><td>Amount Paid</td><td></td></tr>
                    <tr><td>Date Paid</td><td></td></tr>
                </tbody>
            </table>
        </div>
        <div class="right-column">
            <div class="pledge-title">Pledge of Admission</div>
            <div class="pledge-body"><div class="pledge-copy"><strong>Provisional wording — Registrar review required</strong><p>In consideration of my admission to Colegio de Montalban and the privileges granted to students, I hereby pledge to abide by and comply with all rules and regulations established by the competent authorities of Colegio de Montalban.</p></div></div>
            <div class="signature"><div class="signature-line">Student signature over printed name</div></div>
            <div class="signature registrar"><div class="signature-line">Registrar signature over printed name</div></div>
            <div class="stamp">Official school stamp / seal</div>
        </div>
    </div>

    <div class="footer"><strong>Physical verification required</strong><span class="generated">Generated {{ $document['generated_at']->format('Y-m-d H:i:s') }}</span></div>
    <div class="validation">Generating, downloading, or printing this form does not constitute Registrar approval. It requires physical verification, student and Registrar signatures, and the official school stamp/seal. {{ $document['financial_status'] }}</div>
</main>
</body>
</html>
