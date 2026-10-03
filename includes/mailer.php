<?php
/**
 * mailer.php — Shared email helper for Adam Indoors booking system.
 *
 * Provides sendBookingConfirmationEmail() which is called from payhere-notify.php
 * after a successful payment. It correctly computes the grand total as
 * court price + coach price (if any) and sends a styled HTML email.
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../PHPMailer/src/Exception.php';
require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/src/SMTP.php';

/**
 * Send a booking confirmation email after a successful payment.
 *
 * @param string $toEmail    Recipient e-mail address.
 * @param string $toName     Recipient display name.
 * @param array  $bk         Row from bookings JOIN users JOIN courts.
 * @param array  $payRow     Row from payments table (may contain coach_booking_id).
 */
function sendBookingConfirmationEmail(string $toEmail, string $toName, array $bk, array $payRow): void
{
    // ── Fetch coach booking (if any) via the payment row ────────────────────
    $coachInfo  = null;
    $coachPrice = 0.0;

    $coachBookingId = intval($payRow['coach_booking_id'] ?? 0);

    if ($coachBookingId > 0) {
        // Re-open a DB connection inside this helper so it works from any caller.
        try {
            require __DIR__ . '/db.php';
            $cs = $pdo->prepare("
                SELECT cb.*, co.name AS coach_name, co.hourly_rate
                FROM coach_bookings cb
                JOIN coaches co ON cb.coach_id = co.id
                WHERE cb.id = ?
            ");
            $cs->execute([$coachBookingId]);
            $coachInfo  = $cs->fetch();
            $coachPrice = $coachInfo ? floatval($coachInfo['total_price']) : 0.0;
        } catch (Exception $e) {
            // Non-fatal — continue without coach info
        }
    }

    // ── Price calculations ───────────────────────────────────────────────────
    $courtPrice = floatval($bk['total_price']);
    $grandTotal = $courtPrice + $coachPrice;
    $advancePaid = floatval($payRow['amount'] ?? 0) + floatval($payRow['wallet_applied'] ?? 0);
    $balanceDue  = $grandTotal - $advancePaid;

    // ── Format times ────────────────────────────────────────────────────────
    $startFmt = substr($bk['start_time'] ?? '', 0, 5);
    $endFmt   = substr($bk['end_time']   ?? '', 0, 5);

    // ── Build HTML e-mail body ───────────────────────────────────────────────
    $coachSection = '';
    if ($coachInfo) {
        $coachSection = "
        <tr>
            <td style='padding:10px 14px;border-bottom:1px solid #e8f5e9;color:#666;'>Coach</td>
            <td style='padding:10px 14px;border-bottom:1px solid #e8f5e9;font-weight:600;'>"
                . htmlspecialchars($coachInfo['coach_name'])
            . "</td>
        </tr>
        <tr>
            <td style='padding:10px 14px;border-bottom:1px solid #e8f5e9;color:#666;'>Coach Fee</td>
            <td style='padding:10px 14px;border-bottom:1px solid #e8f5e9;font-weight:600;'>LKR "
                . number_format($coachPrice)
            . "</td>
        </tr>";
    }

    $html = "
<!DOCTYPE html>
<html lang='en'>
<head>
  <meta charset='UTF-8'/>
  <meta name='viewport' content='width=device-width,initial-scale=1'/>
  <title>Booking Confirmation</title>
</head>
<body style='margin:0;padding:0;background:#f1f8e9;font-family:Arial,sans-serif;'>

  <table width='100%' cellpadding='0' cellspacing='0' style='background:#f1f8e9;padding:30px 0;'>
    <tr><td align='center'>

      <!-- Card -->
      <table width='580' cellpadding='0' cellspacing='0'
             style='background:#ffffff;border-radius:12px;overflow:hidden;
                    box-shadow:0 4px 20px rgba(0,0,0,.10);'>

        <!-- Header -->
        <tr>
          <td style='background:linear-gradient(135deg,#1b5e20 0%,#43a047 100%);
                     padding:28px 32px;text-align:center;'>
            <p style='margin:0;font-size:32px;'>🎾</p>
            <h1 style='margin:10px 0 4px;color:#ffffff;font-size:22px;font-weight:700;'>
              Booking Confirmed!
            </h1>
            <p style='margin:0;color:#c8e6c9;font-size:14px;'>
              Adam Indoors Sports &amp; Recreation
            </p>
          </td>
        </tr>

        <!-- Greeting -->
        <tr>
          <td style='padding:26px 32px 10px;font-size:15px;color:#333;'>
            Dear <strong>" . htmlspecialchars($toName) . "</strong>,<br/><br/>
            Your booking has been <strong style='color:#2e7d32;'>confirmed</strong> and
            your advance payment has been received. Here is a summary:
          </td>
        </tr>

        <!-- Details table -->
        <tr>
          <td style='padding:10px 32px 24px;'>
            <table width='100%' cellpadding='0' cellspacing='0'
                   style='border:1px solid #c8e6c9;border-radius:8px;overflow:hidden;
                          font-size:14px;'>

              <tr style='background:#e8f5e9;'>
                <td colspan='2' style='padding:10px 14px;font-weight:700;
                    color:#1b5e20;font-size:12px;text-transform:uppercase;
                    letter-spacing:.6px;'>
                  🏟️ Court Booking
                </td>
              </tr>

              <tr>
                <td style='padding:10px 14px;border-bottom:1px solid #e8f5e9;color:#666;'>Booking ID</td>
                <td style='padding:10px 14px;border-bottom:1px solid #e8f5e9;font-weight:700;'>
                  #" . intval($bk['id']) . "
                </td>
              </tr>
              <tr>
                <td style='padding:10px 14px;border-bottom:1px solid #e8f5e9;color:#666;'>Court</td>
                <td style='padding:10px 14px;border-bottom:1px solid #e8f5e9;font-weight:600;'>
                  " . htmlspecialchars($bk['court_name']) . "
                </td>
              </tr>
              <tr>
                <td style='padding:10px 14px;border-bottom:1px solid #e8f5e9;color:#666;'>Date</td>
                <td style='padding:10px 14px;border-bottom:1px solid #e8f5e9;font-weight:600;'>
                  " . htmlspecialchars($bk['date']) . "
                </td>
              </tr>
              <tr>
                <td style='padding:10px 14px;border-bottom:1px solid #e8f5e9;color:#666;'>Time</td>
                <td style='padding:10px 14px;border-bottom:1px solid #e8f5e9;font-weight:600;'>
                  " . htmlspecialchars($startFmt) . " – " . htmlspecialchars($endFmt) . "
                </td>
              </tr>
              <tr>
                <td style='padding:10px 14px;border-bottom:1px solid #e8f5e9;color:#666;'>Court Fee</td>
                <td style='padding:10px 14px;border-bottom:1px solid #e8f5e9;font-weight:600;'>
                  LKR " . number_format($courtPrice) . "
                </td>
              </tr>

              " . $coachSection . "

              <!-- Grand Total -->
              <tr style='background:#e8f5e9;'>
                <td style='padding:14px 14px;font-weight:700;color:#1b5e20;font-size:15px;'>
                  Total Price
                </td>
                <td style='padding:14px 14px;font-weight:700;color:#1b5e20;font-size:15px;'>
                  LKR " . number_format($grandTotal) . "
                </td>
              </tr>

              <!-- Payment rows -->
              <tr>
                <td style='padding:10px 14px;border-top:1px solid #c8e6c9;color:#666;'>
                  Advance Paid (20%)
                </td>
                <td style='padding:10px 14px;border-top:1px solid #c8e6c9;
                    font-weight:700;color:#2e7d32;'>
                  LKR " . number_format($advancePaid) . "
                </td>
              </tr>
              <tr>
                <td style='padding:10px 14px;color:#666;'>Balance Due at Venue (80%)</td>
                <td style='padding:10px 14px;font-weight:700;color:#c62828;'>
                  LKR " . number_format($balanceDue) . "
                </td>
              </tr>

            </table>
          </td>
        </tr>

        <!-- Footer note -->
        <tr>
          <td style='padding:0 32px 28px;font-size:13px;color:#777;line-height:1.7;'>
            Please bring your Booking ID <strong>#" . intval($bk['id']) . "</strong>
            when you arrive at the venue.<br/>
            The remaining balance (LKR " . number_format($balanceDue) . ") is to be paid at the court.
          </td>
        </tr>

        <!-- Footer bar -->
        <tr>
          <td style='background:#1b5e20;padding:18px 32px;text-align:center;
              color:#a5d6a7;font-size:12px;'>
            Thank you for choosing <strong style='color:#fff;'>Adam Indoors</strong>.<br/>
            © " . date('Y') . " Adam Indoors. All rights reserved.
          </td>
        </tr>

      </table>
    </td></tr>
  </table>

</body>
</html>";

    // ── Send via PHPMailer ───────────────────────────────────────────────────
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = getenv('MAIL_HOST')    ?: 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = getenv('MAIL_USERNAME') ?: 'mohamedysn130@gmail.com';
    $mail->Password   = getenv('MAIL_PASSWORD') ?: 'bccadmctjoixahpj';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->setFrom(getenv('MAIL_USERNAME') ?: 'mohamedysn130@gmail.com', 'Adam Indoors');
    $mail->addAddress($toEmail, $toName);
    $mail->isHTML(true);
    $mail->Subject = 'Booking Confirmation – Adam Indoors #' . intval($bk['id']);
    $mail->Body    = $html;
    $mail->AltBody = "Booking Confirmed!\n\nBooking ID: #" . intval($bk['id'])
        . "\nCourt: " . ($bk['court_name'] ?? '')
        . "\nDate: " . ($bk['date'] ?? '')
        . "\nTime: $startFmt – $endFmt"
        . "\nCourt Fee: LKR " . number_format($courtPrice)
        . ($coachInfo ? "\nCoach: " . ($coachInfo['coach_name'] ?? '') . "\nCoach Fee: LKR " . number_format($coachPrice) : '')
        . "\nTotal Price: LKR " . number_format($grandTotal)
        . "\nAdvance Paid: LKR " . number_format($advancePaid)
        . "\nBalance Due at Venue: LKR " . number_format($balanceDue)
        . "\n\nThank you for choosing Adam Indoors.";

    $mail->send();
}

/**
 * Send a booking cancellation email to the customer.
 *
 * @param string $toEmail
 * @param string $toName
 * @param array  $bk
 * @param float  $refundForEmail
 */
function sendBookingCancellationEmail(string $toEmail, string $toName, array $bk, float $refundForEmail): void
{
    $startFmt = substr($bk['start_time'] ?? '', 0, 5);
    $endFmt   = substr($bk['end_time']   ?? '', 0, 5);

    $refundSection = '';
    if ($refundForEmail > 0) {
        $refundSection = "
        <tr>
            <td style='padding:10px 14px;border-top:1px solid #ffcdd2;color:#666;'>
                Amount Refunded to Wallet
            </td>
            <td style='padding:10px 14px;border-top:1px solid #ffcdd2;font-weight:700;color:#c62828;'>
                LKR " . number_format($refundForEmail) . "
            </td>
        </tr>
        <tr>
            <td colspan='2' style='padding:10px 14px;font-size:13px;color:#d32f2f;background:#ffebee;'>
                The advance payment you made has been fully refunded to your Adam Indoors Wallet. 
                You can use this credit for future bookings.
            </td>
        </tr>";
    }

    $html = "
<!DOCTYPE html>
<html lang='en'>
<head>
  <meta charset='UTF-8'/>
  <meta name='viewport' content='width=device-width,initial-scale=1'/>
  <title>Booking Cancelled</title>
</head>
<body style='margin:0;padding:0;background:#ffebee;font-family:Arial,sans-serif;'>

  <table width='100%' cellpadding='0' cellspacing='0' style='background:#ffebee;padding:30px 0;'>
    <tr><td align='center'>

      <!-- Card -->
      <table width='580' cellpadding='0' cellspacing='0'
             style='background:#ffffff;border-radius:12px;overflow:hidden;
                    box-shadow:0 4px 20px rgba(0,0,0,.10);'>

        <!-- Header -->
        <tr>
          <td style='background:linear-gradient(135deg,#c62828 0%,#e53935 100%);
                     padding:28px 32px;text-align:center;'>
            <p style='margin:0;font-size:32px;'>❌</p>
            <h1 style='margin:10px 0 4px;color:#ffffff;font-size:22px;font-weight:700;'>
              Booking Cancelled
            </h1>
            <p style='margin:0;color:#ffcdd2;font-size:14px;'>
              Adam Indoors Sports &amp; Recreation
            </p>
          </td>
        </tr>

        <!-- Greeting -->
        <tr>
          <td style='padding:26px 32px 10px;font-size:15px;color:#333;'>
            Dear <strong>" . htmlspecialchars($toName) . "</strong>,<br/><br/>
            Your booking has been <strong style='color:#c62828;'>cancelled</strong> by the administrator. Here are the details of the cancelled booking:
          </td>
        </tr>

        <!-- Details table -->
        <tr>
          <td style='padding:10px 32px 24px;'>
            <table width='100%' cellpadding='0' cellspacing='0'
                   style='border:1px solid #ffcdd2;border-radius:8px;overflow:hidden;
                          font-size:14px;'>

              <tr style='background:#ffebee;'>
                <td colspan='2' style='padding:10px 14px;font-weight:700;
                    color:#c62828;font-size:12px;text-transform:uppercase;
                    letter-spacing:.6px;'>
                  🏟️ Cancelled Booking Details
                </td>
              </tr>

              <tr>
                <td style='padding:10px 14px;border-bottom:1px solid #ffebee;color:#666;'>Booking ID</td>
                <td style='padding:10px 14px;border-bottom:1px solid #ffebee;font-weight:700;'>
                  #" . intval($bk['id']) . "
                </td>
              </tr>
              <tr>
                <td style='padding:10px 14px;border-bottom:1px solid #ffebee;color:#666;'>Court</td>
                <td style='padding:10px 14px;border-bottom:1px solid #ffebee;font-weight:600;'>
                  " . htmlspecialchars($bk['court_name']) . "
                </td>
              </tr>
              <tr>
                <td style='padding:10px 14px;border-bottom:1px solid #ffebee;color:#666;'>Date</td>
                <td style='padding:10px 14px;border-bottom:1px solid #ffebee;font-weight:600;'>
                  " . htmlspecialchars($bk['date']) . "
                </td>
              </tr>
              <tr>
                <td style='padding:10px 14px;border-bottom:1px solid #ffebee;color:#666;'>Time</td>
                <td style='padding:10px 14px;border-bottom:1px solid #ffebee;font-weight:600;'>
                  " . htmlspecialchars($startFmt) . " – " . htmlspecialchars($endFmt) . "
                </td>
              </tr>

              " . $refundSection . "

            </table>
          </td>
        </tr>

        <!-- Footer note -->
        <tr>
          <td style='padding:0 32px 28px;font-size:13px;color:#777;line-height:1.7;'>
            If you have any questions regarding this cancellation, please contact our support team.
          </td>
        </tr>

        <!-- Footer bar -->
        <tr>
          <td style='background:#c62828;padding:18px 32px;text-align:center;
              color:#ffcdd2;font-size:12px;'>
            Adam Indoors Sports &amp; Recreation.<br/>
            © " . date('Y') . " Adam Indoors. All rights reserved.
          </td>
        </tr>

      </table>
    </td></tr>
  </table>

</body>
</html>";

    // ── Send via PHPMailer ───────────────────────────────────────────────────
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = getenv('MAIL_HOST')     ?: 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = getenv('MAIL_USERNAME')  ?: 'mohamedysn130@gmail.com';
    $mail->Password   = getenv('MAIL_PASSWORD')  ?: 'bccadmctjoixahpj';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->setFrom(getenv('MAIL_USERNAME') ?: 'mohamedysn130@gmail.com', 'Adam Indoors');
    $mail->addAddress($toEmail, $toName);
    $mail->isHTML(true);
    $mail->Subject = 'Booking Cancelled – Adam Indoors #' . intval($bk['id']);
    $mail->Body    = $html;
    $mail->AltBody = "Booking Cancelled!\n\nYour booking ID: #" . intval($bk['id'])
        . " for " . ($bk['court_name'] ?? '')
        . " on " . ($bk['date'] ?? '')
        . " at $startFmt – $endFmt has been cancelled by the admin."
        . ($refundForEmail > 0 ? "\n\nAn amount of LKR " . number_format($refundForEmail) . " has been refunded to your wallet." : "")
        . "\n\nIf you have any questions, please contact our support team.";

    $mail->send();
}
