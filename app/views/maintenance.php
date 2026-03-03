<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Maintenance — Neura</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',sans-serif;background:#0a0a0f;color:#e0e0e0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
.card{text-align:center;max-width:460px;width:100%}
.icon{width:64px;height:64px;margin:0 auto 24px;border-radius:16px;background:rgba(0,212,255,.08);display:flex;align-items:center;justify-content:center}
.icon svg{width:32px;height:32px;stroke:#00d4ff;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
h1{font-size:24px;font-weight:700;margin-bottom:8px;color:#fff}
.sub{font-size:15px;color:#888;line-height:1.7;margin-bottom:32px}
.bar{width:120px;height:3px;border-radius:3px;background:rgba(0,212,255,.15);margin:0 auto;overflow:hidden;position:relative}
.bar::after{content:'';position:absolute;top:0;left:-40%;width:40%;height:100%;background:linear-gradient(90deg,transparent,#00d4ff,transparent);animation:slide 1.8s ease-in-out infinite}
@keyframes slide{0%{left:-40%}100%{left:100%}}
.footer{margin-top:40px;font-size:12px;color:#555}
</style>
</head>
<body>
<div class="card">
    <div class="icon">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
    </div>
    <h1>Maintenance en cours</h1>
    <p class="sub">Nous effectuons une mise a jour pour ameliorer votre experience.<br>Le service sera de retour dans quelques instants.</p>
    <div class="bar"></div>
    <div class="footer">Neura — SEO Local</div>
</div>
</body>
</html>
