<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/10/30 22:01:31
 */

namespace Weline\Smtp\test;

use Exception;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

class PhpMailerTest extends \Weline\Framework\UnitTest\TestCore
{
    public function testPhpMailerSender()
    {
        //Create an instance; passing `true` enables exceptions
        $mail = new PHPMailer(true);

        try {
            //Server settings
            $mail->SMTPDebug = SMTP::DEBUG_SERVER;                                                                                                                                                                                                                                  //Enable verbose debug output
            $mail->isSMTP();                                                                                                                                                                                                                                                        //Send using SMTP
            $mail->Host       = 'smtp.example.com';                                                                                                                                                                                                                                       //Set the SMTP server to send through
            $mail->SMTPAuth = true;                                                                                                                                                                                                                                                 //Enable SMTP authentication
            $mail->Username = 'user@example.com';                                                                                                                                                                                                                                   //SMTP username
            $mail->Password = 'secret';                                                                                                                                                                                                                                             //SMTP password
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;                                                                                                                                                                                                                        //Enable implicit TLS encryption
            $mail->Port       = 465;                                                                                                                                                                                                                                                //TCP port to connect to; use 587 if you have set `SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS`

            //Recipients
            $mail->setFrom('from@example.com', 'Mailer');
            $mail->addAddress('joe@example.net', 'Joe User');     //Add a recipient
            $mail->addAddress('ellen@example.com');               //Name is optional
            $mail->addReplyTo('info@example.com', 'Information');
            $mail->addCC('cc@example.com');
            $mail->addBCC('bcc@example.com');

            //Attachments
            $mail->addAttachment('/var/tmp/file.tar.gz');         //Add attachments
            $mail->addAttachment('/tmp/image.jpg', 'new.jpg');    //Optional name

            //Content
            $mail->isHTML(true);                                  //Set email format to HTML
            $mail->Subject = 'Here is the subject';
            $mail->Body    = 'This is the HTML message body <b>in bold!</b>';
            $mail->AltBody = 'This is the body in plain text for non-HTML mail clients';

            $mail->send();
            echo 'Message has been sent';
        } catch (Exception $e) {
            echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
        }
    }
}
