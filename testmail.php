<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

$mail = new PHPMailer(true);

try {

    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'mohamedysn130@gmail.com';
    $mail->Password   = 'bccadmctjoixahpj';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->setFrom('mohamedysn130@gmail.com', 'Indoor Sports Booking');
    $mail->addAddress('mohamedysn130@gmail.com');

    $mail->isHTML(true);
    $mail->Subject = 'PHPMailer Test';
    $mail->Body    = '<h2>Congratulations!</h2><p>Your PHPMailer is working successfully.</p>';

    $mail->send();

    echo "Email sent successfully.";

} catch (Exception $e) {
    echo "Mailer Error: {$mail->ErrorInfo}";
}
?>