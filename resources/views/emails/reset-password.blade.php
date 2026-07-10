<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"></head>
<body style="font-family: Arial, sans-serif; color: #1e293b; background:#f1f5f9; padding:24px;">
    <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:12px;padding:28px;">
        <h1 style="font-size:18px;margin:0 0 12px;">Réinitialisation de votre mot de passe</h1>
        <p style="font-size:14px;line-height:1.5;">Bonjour {{ $nom }},</p>
        <p style="font-size:14px;line-height:1.5;">
            Vous avez demandé à réinitialiser votre mot de passe. Cliquez sur le bouton
            ci-dessous pour en choisir un nouveau. Ce lien expire dans 60 minutes.
        </p>
        <p style="text-align:center;margin:24px 0;">
            <a href="{{ $url }}" style="background:#059669;color:#fff;text-decoration:none;padding:12px 22px;border-radius:8px;font-size:14px;font-weight:600;display:inline-block;">
                Réinitialiser mon mot de passe
            </a>
        </p>
        <p style="font-size:12px;color:#64748b;line-height:1.5;">
            Si vous n'êtes pas à l'origine de cette demande, ignorez cet email : votre mot de passe reste inchangé.
        </p>
        <p style="font-size:12px;color:#94a3b8;word-break:break-all;">{{ $url }}</p>
    </div>
</body>
</html>
