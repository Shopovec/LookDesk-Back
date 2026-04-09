<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Verification Code</title>
</head>

<body style="margin:0; padding:0; background-color:#f2f2f2; font-family: Arial, sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f2f2f2; padding:40px 0;">
  <tr>
    <td align="center">

      <table width="520" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:10px; overflow:hidden;">
        
        <tr>
          <td style="padding:20px 30px; border-bottom:1px solid #eee;">
            
            <a href="https://lookdesk.ai" target="_blank" style="text-decoration:none; display:inline-block;">
              <table cellpadding="0" cellspacing="0">
                <tr>
                  <td style="vertical-align:middle;">
                    <img src="https://lookdesk.ai/assets/images/logo.svg"
                        alt="LookDesk"
                        width="32"
                        style="display:block;">
                  </td>

                  <td style="vertical-align:middle; padding-left:8px;">
                    <span style="font-family: Arial, sans-serif; font-size:18px; font-weight:600; color:#111;">
                      LookDesk
                    </span>
                  </td>
                </tr>
              </table>
            </a>

          </td>
        </tr>

        <tr>
          <td style="padding:30px;">
            
            <h2 style="margin:0 0 15px 0; font-size:20px; color:#111;">
              One-time verification code
            </h2>

            <p style="margin:0 0 15px 0; color:#555; font-size:14px; line-height:1.5;">
              Here is your one-time login code to verify your email address for your account. 
              This code is valid for 15 minutes only:
            </p>

            <div style="text-align:center; margin:25px 0;">
              <span style="display:inline-block; background:#2f6fed; color:#ffffff; font-size:22px; font-weight:700; padding:10px 20px; border-radius:6px; letter-spacing:2px;">
                {{ $code }}
              </span>
            </div>

            <p style="margin:0 0 15px 0; color:#555; font-size:14px;">Use this unique code to continue signing in to your account.</p>

            <p style="margin:0; color:#999; font-size:12px; line-height:1.5;">
              If you have received this message by mistake, ignore this email. 
              If you think someone else is using your account without your consent, please contact support.
            </p>

          </td>
        </tr>

        <tr>
          <td style="padding:20px 30px; border-top:1px solid #eee; text-align:center;">
            
            <p style="margin:0 0 10px 0; font-size:12px; color:#999;">© {{ date('Y') }} LookDesk. All rights reserved.</p>

            <p style="margin:0; font-size:12px;">
              <a href="https://lookdesk.ai/privacy/" style="color:#2f6fed; text-decoration:none;">Privacy policy</a>
              <a href="https://lookdesk.ai/terms/" style="color:#2f6fed; text-decoration:none; margin-left: 12px;">Terms of Service</a>
            </p>

          </td>
        </tr>

      </table>

    </td>
  </tr>
</table>

</body>
</html>