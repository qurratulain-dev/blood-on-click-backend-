<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Medical Report - Blood Donation</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f0f0f0; padding: 40px; }
        .report-container { max-width: 900px; margin: 0 auto; background: white; box-shadow: 0 5px 30px rgba(0,0,0,0.2); border-radius: 10px; overflow: hidden; }
        .report-header { background: linear-gradient(135deg, #dc3545, #c82333); color: white; padding: 30px; text-align: center; }
        .report-header h1 { font-size: 28px; margin-bottom: 10px; }
        .report-title { background: #f8f9fa; padding: 15px; text-align: center; border-bottom: 2px solid #dc3545; }
        .report-title h2 { color: #dc3545; font-size: 24px; }
        .content { padding: 30px; }
        .section-title { background: #dc3545; color: white; padding: 8px 15px; border-radius: 5px; margin-bottom: 15px; font-size: 16px; }
        .info-table { width: 100%; border-collapse: collapse; }
        .info-table td { padding: 10px; border-bottom: 1px solid #eee; }
        .info-table td:first-child { font-weight: bold; width: 35%; background: #f8f9fa; }
        .medical-results { display: flex; flex-wrap: wrap; gap: 15px; margin-top: 15px; }
        .result-card { flex: 1; min-width: 150px; background: #f8f9fa; padding: 15px; text-align: center; border-radius: 8px; border-left: 4px solid #dc3545; }
        .result-card .label { font-size: 12px; color: #666; margin-bottom: 5px; }
        .result-card .value { font-size: 20px; font-weight: bold; color: #dc3545; }
        .status-badge { display: inline-block; padding: 5px 15px; border-radius: 20px; font-weight: bold; }
        .status-fit { background: #28a745; color: white; }
        .footer { background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #666; border-top: 1px solid #dee2e6; }
        .signatures { display: flex; justify-content: space-between; margin-top: 30px; padding-top: 20px; }
        .sign-line { text-align: center; width: 200px; }
        .sign-line .line { border-top: 1px solid #333; margin-top: 40px; margin-bottom: 5px; }
        .print-btn { text-align: center; padding: 20px; background: #f8f9fa; }
        .print-btn button { background: #dc3545; color: white; border: none; padding: 10px 30px; border-radius: 5px; cursor: pointer; margin: 0 10px; }
        @media print { body { background: white; padding: 0; } .print-btn { display: none; } }
    </style>
</head>
<body>
    <div class="report-container">
        <div class="report-header">
            <h1>Blood on Click Medical Center</h1>
            <p>Authorized Blood Donation & Health Assessment Center</p>
        </div>
        <div class="report-title">
            <h2>POST-DONATION MEDICAL REPORT</h2>
            <div>Report No: {{ $reportNo }} | Issue Date: {{ $reportDate }}</div>
        </div>
        <div class="content">
            <div class="donor-info">
                <div class="section-title">DONOR INFORMATION</div>
                <table class="info-table">
                    <tr><td>Full Name</td><td><strong>{{ strtoupper($donation->donor->full_name ?? 'N/A') }}</strong></td></tr>
                    <tr><td>Age</td><td>{{ $donation->donor->age ?? 'N/A' }} years</td></tr>
                    <tr><td>Gender</td><td>{{ $gender }}</td></tr>
                    <tr><td>Blood Group</td><td><strong>{{ $donation->blood_group }}</strong></td></tr>
                    <tr><td>Contact Number</td><td>{{ $donation->donor->phone ?? 'N/A' }}</td></tr>
                    <tr><td>Email Address</td><td>{{ $donation->donor->user->email ?? 'N/A' }}</td></tr>
                    <tr><td>Address</td><td>{{ $donation->donor->address ?? 'N/A' }}</td></tr>
                </table>
            </div>
            <div class="donation-info">
                <div class="section-title">DONATION INFORMATION</div>
                <table class="info-table">
                    <tr><td>Blood Bank</td><td><strong>{{ $donation->bank->bank_name ?? 'N/A' }}</strong></td></tr>
                    <tr><td>Bank Address</td><td>{{ $donation->bank->address ?? 'N/A' }}</td></tr>
                    <tr><td>Bank Contact</td><td>{{ $donation->bank->phone ?? 'N/A' }}</td></tr>
                    <tr><td>Donation Date</td><td><strong>{{ $donationDate }}</strong></td></tr>
                    <tr><td>Units Donated</td><td>{{ $donation->quantity }} Unit(s) [450ml per unit]</td></tr>
                </table>
            </div>
            <div class="medical-info">
                <div class="section-title">MEDICAL ASSESSMENT RESULTS</div>
                <div class="medical-results">
                    <div class="result-card"><div class="label">Blood Pressure</div><div class="value">{{ $bloodPressure }}</div></div>
                    <div class="result-card"><div class="label">Hemoglobin</div><div class="value">{{ $hemoglobin }}</div></div>
                    <div class="result-card"><div class="label">Weight</div><div class="value">{{ $weight }}</div><div class="unit">kg</div></div>
                </div>
            </div>
            <div class="donation-info">
                <div class="section-title">DONATION STATUS</div>
                <table class="info-table">
                    <tr><td>Donation Eligibility</td><td><span class="status-badge status-fit">{{ $eligibility }}</span></td></tr>
                    <tr><td>Donation Status</td><td><span class="status-badge status-fit">Completed Successfully</span></td></tr>
                    <tr><td>Next Eligible Date</td><td><strong>{{ $nextEligible }}</strong> (After 90 days)</td></tr>
                </table>
            </div>
            <div class="medical-info">
                <div class="section-title">DOCTOR'S NOTES & RECOMMENDATIONS</div>
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px;">
                    <ul style="margin-left: 20px; line-height: 1.8;">
                        <li>Thank you for your generous blood donation.</li>
                        <li>Drink plenty of fluids and avoid strenuous activities for the next 24 hours.</li>
                        <li>Eat iron-rich foods like spinach, red meat, and beans to replenish iron stores.</li>
                        <li>Your next donation is eligible after 90 days from today.</li>
                    </ul>
                </div>
            </div>
            <div class="signatures">
                <div class="sign-line"><div class="line"></div><p>Donor Signature</p></div>
                <div class="sign-line"><div class="line"></div><p>Medical Officer</p></div>
                <div class="sign-line"><div class="line"></div><p>Blood Bank Stamp</p></div>
            </div>
        </div>
        <div class="footer">
            <p>This is a computer-generated medical report.</p>
            <p>&copy; {{ date('Y') }} Blood on Click - Saving Lives Through Blood Donation</p>
        </div>
        <div class="print-btn">
            <button onclick="window.print()">Print Medical Report</button>
            <button onclick="window.close()">Close</button>
        </div>
    </div>
</body>
</html>
