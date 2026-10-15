<h1>DOCUMENT REQUEST APPROVED</h1>
<p><strong>Document:</strong> {{ $documentRequest->documentType->document_name }}</p>
<p><strong>Appointment Date:</strong> {{ $documentRequest->activeAppointment->appointment_date->format('F j, Y') }}</p>
<p><strong>Verification / Claim Code:</strong> {{ $claimCode }}</p>
<p><strong>Registrar Window:</strong> {{ $registrarWindow }}</p>
<p>Present this verification code when claiming your document.</p>
