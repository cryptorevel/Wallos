<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json; charset=utf-8');
ob_start();

function smtp_test_response(array $response): void
{
    if (ob_get_length() !== false) {
        ob_clean();
    }

    echo json_encode($response);
    exit;
}

set_error_handler(static function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require_once '../../includes/connect_endpoint.php';
    require_once '../../includes/validate_endpoint.php';
    require_once '../../includes/ssrf_helper.php';

    $postData = file_get_contents("php://input");
    $data = json_decode($postData, true);

    if (
        !is_array($data) ||
        !isset($data["smtpaddress"]) || trim($data["smtpaddress"]) == "" ||
        !isset($data["smtpport"]) || trim((string) $data["smtpport"]) == ""
    ) {
        smtp_test_response([
            "success" => false,
            "message" => translate('fill_all_fields', $i18n)
        ]);
    }

    $encryption = "none";
    if (isset($data["encryption"])) {
        $encryption = $data["encryption"];
    }

    $smtpAuth = (isset($data["smtpusername"]) && $data["smtpusername"] != "") || (isset($data["smtppassword"]) && $data["smtppassword"] != "");

    require '../../libs/PHPMailer/PHPMailer.php';
    require '../../libs/PHPMailer/SMTP.php';
    require '../../libs/PHPMailer/Exception.php';

    $smtpAddress = trim($data["smtpaddress"]);
    $smtpPort = (int) $data["smtpport"];

    if (!validate_smtp_host($smtpAddress, $smtpPort, $db)) {
        smtp_test_response([
            "success" => false,
            "message" => "Security Error: SMTP host must not target link-local or loopback addresses."
        ]);
    }

    if ($smtpPort < 1 || $smtpPort > 65535) {
        smtp_test_response([
            "success" => false,
            "message" => translate('fill_all_fields', $i18n)
        ]);
    }
    $smtpUsername = trim((string) ($data["smtpusername"] ?? ''));
    $smtpPassword = (string) ($data["smtppassword"] ?? '');
    $fromEmail = trim((string) ($data["fromemail"] ?? ''));
    $fromEmail = $fromEmail !== '' ? $fromEmail : "wallos@wallosapp.com";

    if (!PHPMailer::validateAddress($fromEmail)) {
        error_log('Wallos SMTP test rejected an invalid From email address.');
        smtp_test_response([
            "success" => false,
            "message" => "From email must be a valid email address."
        ]);
    }

    $mail = new PHPMailer(true);
    $mail->CharSet = "UTF-8";
    $mail->isSMTP();
    $mail->Timeout = 15;

    $mail->Host = $smtpAddress;
    $mail->SMTPAuth = $smtpAuth;
    if ($smtpAuth) {
        $mail->Username = $smtpUsername;
        $mail->Password = $smtpPassword;
    }

    if ($encryption != "none") {
        $mail->SMTPSecure = $encryption;
    } else {
        $mail->SMTPSecure = false;
        $mail->SMTPAutoTLS = false;
    }

    $mail->Port = $smtpPort;

    $getUser = "SELECT * FROM user WHERE id = $userId";
    $user = $db->querySingle($getUser, true);
    $email = $user['email'];
    $name = $user['username'];

    $mail->setFrom($fromEmail, 'Wallos App');
    $mail->addAddress($email, $name);

    $mail->Subject = translate('wallos_notification', $i18n);
    $mail->Body = translate('test_notification', $i18n);

    if ($mail->send()) {
        $response = [
            "success" => true,
            "message" => translate('notification_sent_successfuly', $i18n)
        ];
    } else {
        throw new Exception($mail->ErrorInfo);
    }

    smtp_test_response($response);
} catch (Throwable $e) {
    $logMessage = str_replace(["\r", "\n"], ' ', $e->getMessage());
    error_log(sprintf(
        'Wallos SMTP test failed [%s] in %s:%d: %s',
        get_class($e),
        basename($e->getFile()),
        $e->getLine(),
        $logMessage
    ));

    smtp_test_response([
        "success" => false,
        "message" => "Unable to send test email. Check SMTP settings and server logs."
    ]);
} finally {
    restore_error_handler();
}
