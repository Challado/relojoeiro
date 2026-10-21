<!-- origem: 3e6e2bb79de85133f8f9faa830cbcc1ac5246ed0 -->
<!-- parte 1 -->
# ⌚ Relojoeiro — Relógios 2

🌐 [Português](README.md) · [English](README.en.md) · [中文](README.zh-CN.md) · **हिन्दी** · [Español](README.es.md) · [Français](README.fr.md)

> **घड़ियों के संग्रह का स्मार्ट रोटेशन।** हर दिन बताता है कि कौन सी घड़ी कलाई पर जाएगी, चाबी देने, चार्ज करने, धूप में
> रखने और बैटरी बदलने की याद दिलाता है, और पूरा संग्रह पहना जाए यह सुनिश्चित करता है, सिर्फ़ पसंदीदा नहीं। कई लोग एक ही
> सिस्टम का उपयोग करते हैं, हर एक अपने संग्रहों के साथ, और जो चाहें साझा करते हैं।

**[▶ अभी आज़माएँ, कुछ भी इंस्टॉल किए बिना](https://servidor2.lucas.inf.br/relojoeiro/cadastro.php)**: प्रोजेक्ट के सर्वर पर एक डेमो खाता बनाएँ (30 दिन
मान्य, अपनी घड़ियाँ दर्ज करने के लिए तैयार एक संग्रह के साथ)।

![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777bb4?logo=php&logoColor=white)
![SQLite, MySQL/MariaDB, PostgreSQL या Firebird](https://img.shields.io/badge/database-SQLite%20%7C%20MySQL%20%7C%20PostgreSQL%20%7C%20Firebird-336791)
![कोई निर्भरता नहीं](https://img.shields.io/badge/dependencies-none-2ea44f)
![REST API](https://img.shields.io/badge/API-REST%20%2B%20JSON%2FXML-0366d6)
[![लाइसेंस](https://img.shields.io/badge/license-source--available-orange)](#licença)

![आज पेज: दिन की घड़ी, आज क्या करना है, अगले दिनों का शेड्यूल और एक घड़ी का पैनल](docs/telas/hoje.png)

<sub>8 घड़ियों के एक डेमो कलेक्शन के साथ **आज** पेज (आम मॉडल, API से दर्ज छह हफ़्ते का उपयोग): दिन की घड़ी (Casio, सुबह 7
बजे से कलाई पर), आज क्या करना है, अगले दिनों का स्मार्ट शेड्यूल और दाईं ओर Tissot का पैनल, सूची के बगल में खुला (सेटिंग्स
में यह पेज के ऊपर फ़्लोटिंग भी खुल सकता है)।</sub>

| | |
|---|---|
| **[यह क्यों है](#por-que-isso-existe)** · [मूल विचार](#a-ideia-central-o-sistema-não-sabe-nada-de-relógios) · [यह कैसे काम करता है](#como-funciona) · [इसके साथ एक दिन](#um-dia-com-o-relógios-2) | **[1 मिनट में आज़माएँ](#experimente-em-1-minuto)** · [स्क्रीनें](#as-telas) · [API](#a-api-uma-porta-só-para-tudo) · [इंस्टॉलेशन](#instalação) |
| [कई लोग](#várias-pessoas-contas-coleções-e-acesso) ·[अक्सर पूछे जाने वाले सवाल](#perguntas-frequentes) · [भीतर से यह कैसा है](#como-é-por-dentro) | [योगदान कैसे करें](#como-contribuir) · [लाइसेंस](#licença) |

---

## यह क्यों बना {#por-que-isso-existe}

जो घड़ियाँ इकट्ठा करता है, वह यह दृश्य जानता है:

- **पसंदीदा घड़ी रोज़ कलाई पर जाती है**, और जो आपने इतने चाव से ख़रीदी थी वह दो महीने से दराज़ में पड़ी है।
- **ऑटोमैटिक रुक जाती है** क्योंकि सप्ताहांत में किसी ने नहीं पहनी, और फिर से समय, तारीख़ और चाँद की कला मिलानी पड़ती है।
- **सोलर डिब्बे के अँधेरे में ख़ाली हो जाती है**, और आपको पता भी नहीं चलता।
- **बैटरी बिना बताए ख़त्म हो जाती है**, ठीक उसी घड़ी में जो आप आज पहनना चाहते थे।
- **स्मार्टवॉच सुबह बिना बैटरी के मिलती है**, उसी दिन जब उसकी बारी थी।
- **मैकेनिकल की सर्विस** ("हर 5 साल") और **वारंटी** ("क्या मार्च में ख़त्म होती है?") सिर्फ़ याद में रहती हैं, यानी कहीं नहीं।

स्प्रेडशीट आधी समस्या हल करती है: वह याद रखती है, पर फ़ैसला नहीं करती। **Relógios 2** फ़ैसला करता है। वह जानता है कि हर
घड़ी कितने समय से पड़ी है, अभी उसमें कितना चार्ज है, आपको वह कितनी पसंद है और बाक़ी की तुलना में वह कितनी पहनी गई। इससे वह
अगले दिनों की, या अगले दो सालों की, योजना बनाता है, रिमाइंडर पहले से सही जगह पर रखकर।

सुबह Telegram पर एक संदेश आता है: *"आज: Seiko 5। Orient को चाबी दें (रिज़र्व दोपहर 3 बजे ख़त्म होगा)।"*
शाम को दूसरा: *"कल: Xiaomi Band। सोने से पहले चार्ज करें: कल दोपहर 2 बजे 20% पर पहुँचेगी।"*
और Google कैलेंडर में नवंबर में Casio की बैटरी बदलना और 2027 में Tissot की सर्विस दर्ज है।

<!-- parte 2 -->
## 1 मिनट में आज़माएँ {#experimente-em-1-minuto}

**बिना इंस्टॉल किए:** [servidor2.lucas.inf.br/relojoeiro](https://servidor2.lucas.inf.br/relojoeiro/cadastro.php) पर एक डेमो खाता बनाएँ
(30 दिन मान्य; खाता एक संग्रह और प्रारंभिक सेट के साथ बनता है, और उसमें आप जो दर्ज करते हैं वह केवल आप देखते हैं)।

**अपनी मशीन पर:** बस `sqlite3` एक्सटेंशन वाला PHP 8.1+ चाहिए: न वेब सर्वर, न डेटाबेस सर्वर।

```sh
git clone https://github.com/Challado/relojoeiro.git && cd relojoeiro
cp config.exemplo.php config.php
```

`config.php` में SQLite इस्तेमाल करने के लिए दो पंक्तियाँ बदलें (डेटाबेस की फ़ाइल फ़ोल्डर के बगल में रहती है):

```php
define("DB_TIPO", "sqlite");
define("DB_ARQUIVO", __DIR__ . "/../relogios.sqlite");
```

```sh
php instalar.php --lingua=hi  # संरचना और शुरुआती सेट (हिंदी में)
php criar_usuario.php aap     # पासवर्ड पूछता है
php -S localhost:8000         # और http://localhost:8000 खोलें
```

**आज → नई घड़ी** में कुछ घड़ियाँ दर्ज करें। cron का इंतज़ार किए बिना शेड्यूल बनाने के लिए `php cron.php --forcar` चलाएँ।

> PHP का बिल्ट-इन सर्वर (`php -S`) सिर्फ़ आज़माने के लिए है: यह फ़ोल्डर की कोई भी फ़ाइल दे देता है, `config.php` भी। असली
> उपयोग के लिए [इंस्टॉलेशन](#instalação) देखें।

---

<!-- parte 3 -->
## API: हर चीज़ के लिए एक ही दरवाज़ा {#a-api-uma-porta-só-para-tudo}

`api.php` **एकमात्र बैक-एंड** है। पेज सिर्फ़ स्क्रीन हैं: हर पेज का JavaScript API को बुलाता है और HTML बनाता है, कोई गणना किए
बिना (सारांश, प्रतिशत, जोड़ और स्थितियाँ, जैसे योजना का सारांश और cron की स्थिति, API से तैयार आते हैं; स्क्रीन पर सिर्फ़ उसका
अपना काम रहता है: फ़िल्टर करना, क्रम देना और तालिका के फ़िल्टर जो दिखाते हैं उसे जोड़ना)। इसलिए **जो कुछ स्क्रीन करती है, वह
एक स्क्रिप्ट भी कर सकती है**: स्वचालन, फ़ोन के शॉर्टकट, Home Assistant के साथ जोड़, एक विजेट।

API वह भी करती है जो स्क्रीनों से पहले और उनके आसपास आता है: **डेटाबेस इंस्टॉल करती है** (`recurso=instalacao`), **पहला
उपयोगकर्ता बनाती है** (`recurso=usuarios`, टोकन से), **पिछला सिस्टम आयात करती है** (`recurso=importacao`), **खुला पंजीकरण**
(`recurso=cadastro`, बिना लॉगिन), **खाते** (`conta`, `colecoes`, `acessos`, `convites`, `pedidos`, `aprovacoes`) और **पृष्ठों का
लॉगिन** करती है (`recurso=entrar`: पृष्ठ खुला रहते लॉगिन चला जाए तो API उपयोगकर्ता और पासवर्ड माँगती है और पृष्ठ पर वापस भेजती है;
खोलते समय पृष्ठ ख़ुद कुछ भी भेजने से पहले ही माँग लेता है)। कमांड लाइन की स्क्रिप्ट (`instalar.php`,
`criar_usuario.php`, `importar.php`) शॉर्टकट हैं: वे API के वही ऑपरेशन बुलाती हैं।

API के बाहर केवल **cron** है (`cron.php`, सिस्टम की घड़ी: योजना बनाता है, दिन का सत्र खोलता और बंद करता है, संदेश भेजता है,
कैलेंडर सिंक करता है, इवेंट ट्रिगर करता है और दस्तावेज़ों की प्रतियों की देखभाल करता है), जो वही फ़ंक्शन उपयोग करता है जो API
करती है; जो तुरंत माँगा जा सकता है उसकी API में कार्रवाई है (गंतव्यों का `testar`, कैलेंडरों का `sincronizar`, `resortear`...),
और उसने जो किया वह `recurso=cron` में दिखता है।

**प्रमाणीकरण:** साइट का लॉगिन (HTTP Basic), एक **व्यक्तिगत टोकन** (**मेरा खाता** में बनाया गया, आपके लॉगिन जैसा मान्य), कुकी में **याद रखे डिवाइस** की कुंजी (फ़ोन पर ऐप की कुकी) या
सर्वर का टोकन (`API_TOKEN`, master जैसा मान्य), `X-Api-Token` हेडर में या `?token=...` में। **संग्रह:** `colecao=<id>` (इसके
बिना, मेनू के चयनकर्ता वाला, नहीं तो आपका पहला); जिसे आप नहीं देखते वह 404 देता है। **हटाने** के लिए `confirmar=1` चाहिए:
इसके बिना, उत्तर (409) बताता है कि साथ में क्या जाएगा और कुछ नहीं हटता।
**फ़ॉर्मेट:** डिफ़ॉल्ट JSON, या `formato=xml` के साथ XML। **भाषा:** `lingua=hi` (या `Accept-Language` हेडर): संदेश, सहायता
और पुनर्निर्माण उसी भाषा में आते हैं।

```sh
# सिस्टम में सब कुछ, फ़ोटो के बिना
curl -u upyogkarta:password "http://server/relojoeiro/api.php?foto=nao"

# आज पेज जो दिखाता है
curl -H "X-Api-Token: $TOKEN" "http://server/relojoeiro/api.php?recurso=hoje"

# अगले 24 घंटों में बंद होने वाली घड़ियाँ, सिर्फ़ नाम और कब
curl -u upyogkarta:password -g "http://server/relojoeiro/api.php?recurso=autonomia&f[relogios][acaba_em_segundos][ate]=86400&mostrar[relogios]=nome,acaba_em_datacomtz"

# चार्ज की एक रीडिंग दर्ज करें (और खपत मापें); estado=uso या repouso: पढ़ने के समय वह कैसी थी (वैकल्पिक)
curl -u upyogkarta:password -d recurso=lancamento -d acao=lancar -d relogio_id=10 -d tipo=carga -d valor=68 -d estado=repouso http://server/relojoeiro/api.php

# अभी धूप में रखें... और बाद में उतारें
curl -u upyogkarta:password -d recurso=lancamento -d acao=iniciar  -d relogio_id=4 -d tipo=sol http://server/relojoeiro/api.php
curl -u upyogkarta:password -d recurso=lancamento -d acao=encerrar -d relogio_id=4 -d tipo=sol http://server/relojoeiro/api.php

# दो घड़ियों पर एक सूत्र जाँचें, बिना सहेजे
curl -u upyogkarta:password -G "http://server/relojoeiro/api.php" -d recurso=calcular --data-urlencode "expressao=energia * 2" -d relogio=10,12
```

**पूछताछ** (`GET`, `recurso=` के साथ):

| संसाधन | क्या लौटाता है |
|---|---|
| *(कोई नहीं)* | जो कुछ सहेजा है: पेड़, फ़ील्ड, प्रविष्टि प्रकार, सूत्र, अलर्ट, घड़ियाँ (मानों, गणना किए सूत्रों, अलर्ट, प्रविष्टियों, पूर्वानुमान, फ़ोटो और दस्तावेज़ों के डेटा के साथ), दस्तावेज़ श्रेणियाँ, मोड, योजना, सेटिंग्स, इवेंट, कैलेंडर, मानदंड, cron, उपयोगकर्ता (पासवर्ड के हैश के साथ: पासवर्ड सिस्टम में मौजूद ही नहीं) और माइग्रेशन। `foto=nao` फ़ोटो छोड़ देता है। |
| `hoje` | आज पेज जो दिखाता है: दिन की घड़ी, अलर्ट, अगले दिन, मोड, संग्रह की तालिका और उसके जोड़ (कितनी घड़ियाँ, उपलब्ध, उपयोग में और चुकाई गई क़ीमत) |
| `ficha` | एक घड़ी का पैनल जो दिखाता है (`relogio=<id>`; इसके बिना, सिर्फ़ नई घड़ी का रिकॉर्ड): अभी, चार्ज, अंक, पूर्वानुमान, बैटरी की खपत, हर श्रेणी में कितने दस्तावेज़, हाल के निशान, रिकॉर्ड |
| `proximos` | आज पेज के अगले दिन, एक पेज (`pagina=`, `por_pagina=`; इसके बिना, सेटिंग्स का "इतने तक दिखाएँ"), बीच में ख़ाली दिनों के साथ |
| `rodizio_manual` | मैनुअल रोटेशन: किसी अवधि (`de=`, `ate=`) के दिन 7-7 करके (`pagina=`), हर एक के साथ कि योजना में अभी क्या है और चयनकर्ता की शुरुआती पसंद (हाथ से चुनी घड़ी, `livre` या `auto`: मेरे लिए तय करें), उपलब्ध घड़ियाँ और "मेरे लिए तय करें" का मानदंड |
| `plano` | सहेजी गई योजना, दिन-ब-दिन, हर चुनाव के कारण के साथ, अवधि का सारांश जैसा योजना पेज दिखाता है (हर घड़ी के कितने दिन, प्रतिशत, अगला दिन, कितने हाथ से, कौन बिना दिन रह गई) और `dia_a_dia` एक बार में एक पेज (`pagina=`, `por_pagina=`, `relogio=`)। `de=` और `ate=` (YYYY-MM-DD; इनके बिना, पूरी योजना) या `dias=N` (अगले N दिन; `dias=todos`, आज से अंत तक) |
| `avisos` | सभी घड़ियों के अलर्ट, सबसे ज़रूरी से सबसे दूर तक |
| `previsao` | चार्ज रीडिंग वाली घड़ियों की बैटरी का पूर्वानुमान (`relogio=` एक id या अल्पविराम से अलग कई; इसके बिना, सभी) |
| `autonomia` | हर घड़ी कितनी चलती है, सेकंड में, बाहरी सिस्टमों के लिए छोटा किया हुआ (`relogio=` एक id या कई; इसके बिना, सभी) |
| `historico` | एक घड़ी की समयरेखा, हर स्थिति के समय के साथ। `relogio=` (एक id या कई; `relogio_id` भी चलता है; इसके बिना, सभी), `de=` और `ate=` (तारीख़, या तारीख़ और समय: अवधि को काटने वाला हिस्सा आता है), `estado=` (`rodizio`, `pulso`, `repouso`, `marca` या कोई सत्र प्रकार जैसे `winder` और `sol`; एक या कई; `linha_estado` भी चलता है), `ordem=` (`desc`, डिफ़ॉल्ट, या `asc`), `limite=` (डिफ़ॉल्ट 100, अधिकतम 1000) और `pagina=` |
| `criterios` | मानदंडों के सेट और गणना के साथ हर घड़ी के अंक |
| `eventos`, `destinos`, `agendas` | कस्टम इवेंट; संदेशों के गंतव्य, हर एक क्या पाता है और आज के पूर्वावलोकन सहित; कैलेंडर, हर एक में क्या होना चाहिए उसके साथ |
| `ics` | ICS लिंक प्रकार के कैलेंडर का कैलेंडर (बिना लॉगिन, लिंक की कुंजी से) |
| `config`, `cron` | संग्रह की सेटिंग्स (गंतव्यों और कैलेंडरों के सारांश और cron की स्थिति के साथ: कभी नहीं चला, रुका हुआ या चल रहा); cron के रन, अवधि के सारांश के साथ। `cron` में: `de=` और `ate=` (तारीख़, या तारीख़ और समय), `situacao=` (`atividade`, डिफ़ॉल्ट; `erro`, `nada` या `todas`), `busca=` (लॉग में पाठ), `limite=` (डिफ़ॉल्ट 100, अधिकतम 1000) और `pagina=`; हर एक आगे `cron_` के साथ भी मान्य है (`cron_de`...) |
| `arvore`, `cadastros` | समूह; परिभाषाएँ पेज जो कुछ दिखाता है |
| `calcular` | घड़ियों पर किसी सूत्र (`expressao=`) का नतीजा (`relogio=` एक id या कई; इसके बिना, सभी), बिना सहेजे, गणना के हिस्सों के साथ; ग़लत लिखा सूत्र: त्रुटियों के साथ 400 |
| `foto` | एक घड़ी की फ़ोटो (`relogio=<id>`): छवि, JSON नहीं |
| `documentos` | एक घड़ी के दस्तावेज़ (`relogio=<id>`; इसके बिना, सबके), श्रेणियों, हर XML के NF-e सारांश, फ़ोल्डर और सीमा के साथ |
| `servicos` | सर्विस और जाँच (`relogio=<id>`, सारांश के साथ: घोषित जल-प्रतिरोध, सिद्ध जल-रोधकता, कितनी और कुल लागत; इसके बिना, सबकी), पुर्ज़ों, लागत, जल-रोधकता और पहले व बाद की गति के साथ |
| `documento` | एक दस्तावेज़ की फ़ाइल (`id=<id>`): दिखाने के लिए (छवि, वीडियो, ऑडियो, PDF, टेक्स्ट) या डाउनलोड के लिए (बाक़ी, या `baixar=1` के साथ); `mini=1` फ़ोटो का थंबनेल है। टुकड़ों (Range) में देता है, ताकि वीडियो आगे बढ़ सके |
| `usuarios`, `migracoes` | master: खातों का पैनल, हर खाता उसके पास जो है और पासवर्ड के हैश के साथ (उसी पासवर्ड के साथ माइग्रेट करने के लिए: देखें [उपयोगकर्ता और पासवर्ड](#os-usuários-e-as-senhas)); बाकी, केवल अपना खाता; लागू करने को बचे माइग्रेशन |
| `conta`, `colecoes`, `aprovacoes` | मेरा खाता (संग्रह, वृक्ष, अनुरोध, टोकन, याद रखे डिवाइस, Web Push के उपकरण; `parte=menu`: केवल मेनू वाला हिस्सा); संग्रह और वृक्ष; स्वीकृतियाँ पृष्ठ |
| `manifest`, `sw` | फ़ोन पर ऐप, बिना लॉगिन: मैनिफ़ेस्ट (JSON; याद रखे डिवाइस की कुकी के साथ, `start_url` पहले से प्रवेश करता है) और *service worker* (JavaScript)। देखें [फ़ोन पर ऐप](#o-aplicativo-no-celular) |
| `cadastro` | खुला पंजीकरण, बिना लॉगिन: खुला है या नहीं और निमंत्रण (`convite=<token>`); खातों या संग्रहों की कोई सूची नहीं (पहुँच का अनुरोध स्वामी के दिए कोड से होता है) |
| `instalacao` | इंस्टॉलेशन की स्थिति: डेटाबेस, क्या वह ख़ाली है, इंस्टॉल और अद्यतन है, कितने उपयोगकर्ता और अगला क़दम। ख़ाली डेटाबेस पर भी, टोकन से, जवाब देता है |
| `backup` | डेटाबेस का बैकअप: क्या वह चालू है, क्या फ़ोल्डर ठीक है, कितनी रखी जाती हैं, आख़िरी और फ़ोल्डर की कॉपियाँ; `arquivo=` एक डाउनलोड करता है |
| `importacao` | पिछले सिस्टम का आयात क्या इस्तेमाल करता है: पुराने डेटाबेस का सर्वर (पासवर्ड के बिना), क्या PHP में MySQL है और क्या इस डेटाबेस में पहले से घड़ियाँ हैं |
| `entrar` | पेजों का लॉगिन: बिना लॉगिन, 401 (ब्राउज़र उपयोगकर्ता और पासवर्ड माँगता है); लॉगिन के साथ, `volta=` वाले पेज पर लौटता है (302) या बताता है कि कौन आया |
| `ajuda` | पूरा दस्तावेज़ीकरण: हर पूछताछ, हर लेखन, हर जवाब का हर फ़ील्ड और इंजन के फ़ंक्शन |
| `manual` (या `ajuda&parte=manual`) | **सहायता** पेज का टेक्स्ट (इस README के उपयोगकर्ताओं वाले हिस्से: मूल विचार से अक्सर पूछे जाने वाले सवालों तक), markdown में, पूरा और हिस्सा-दर-हिस्सा, हर एक के शीर्षक, स्तर और एंकर के साथ, और विषय-सूची के साथ पहले से HTML में (सहायता पेज इसी से बनता है) |
| `reconstrucao` (या `ajuda&parte=reconstrucao`) | **पूरे सिस्टम को शून्य से दोबारा लिखने** की गाइड (देखें [सिस्टम को शून्य से दोबारा लिखें](#reescrever-o-sistema-do-zero)) |
| `codigo` (या `ajuda&parte=codigo`) | **सिस्टम का कोड जैसा अभी है**, उसी क्षण डिस्क से पढ़ा हुआ: हर फ़ाइल परत, वह क्या है, भाषा, आकार, पंक्तियों, SHA-256 और तारीख़ के साथ, और जो जान-बूझकर छोड़ा गया है (`nao_mostrados`)। `arquivo=<नाम>` सामग्री लाता है (छवि base64 में; किसी पेज के लिए, वह HTML भी जो ब्राउज़र को मिलता है); `cru=1` बिना JSON के कच्ची फ़ाइल लाता है, ब्राउज़र में पढ़ने के लिए; `conteudo=1` सब लाता है (देखें [सिस्टम को शून्य से दोबारा लिखें](#reescrever-o-sistema-do-zero)) |

**लेखन** (`POST`, `recurso=` और `acao=` के साथ), सब स्क्रीन वाली ही जाँचों और संदेशों के साथ:

| संसाधन | क्रियाएँ |
|---|---|
| `relogio` | `salvar` (बनाना या बदलना), `excluir` (दस्तावेज़ों के साथ), `foto`, `remover_foto` |
| `documentos` | `enviar` (एक या कई फ़ाइलें, श्रेणी, शीर्षक, तारीख़, विवरण और, फ़ोटो में, थंबनेल के साथ), `alterar`, `excluir` |
| `servicos` | `salvar` (तारीख़, प्रकार, किसने किया, मज़दूरी, पुर्ज़े, जल-रोधकता, पहले व बाद की गति, वारंटी और दस्तावेज़; सर्विस और बैटरी बदलना प्रविष्टि भी बन जाते हैं), `excluir` |
| `documento_categorias` | `nova`, `alterar`, `excluir` (सिर्फ़ बिना दस्तावेज़ों के) |
| `lancamento` | `lancar` (चाबी, चार्ज, बैटरी...), `iniciar` और `encerrar` (कलाई, वाइंडर, धूप, चार्जर पर रखना और उतारना), `alternar` (सत्र खुला हो तो बंद करता है, बंद हो तो खोलता है: फ़ोन पर एक ही बटन), `periodo` (बीत चुकी अवधि; दिन की घड़ी की कलाई पर यह रोटेशन का उपयोग है, और उसी प्रकार के खंड जिनसे यह छूती है, जैसे खुला छोड़ा गया पहनें, एक हो जाते हैं), `alterar`, `excluir`, `medicao` (खपत का कोई माप गणना में आता है या उससे निकलता है: `id`, `usada` 1 या 0)। सबमें `relogio_id=hoje` दिन की घड़ी है |
| `rodizio` | `usando` (आज मैं यह पहन रहा हूँ), `trocar_dia` (किसी दिन की घड़ी), `periodo` (किसी अवधि की घड़ियाँ, क्रम से: `de`, `ate`, `relogio_ids[]`, `dias_cada`), `periodo_sortear` (अवधि के दिन हटाता है और मोड उन्हें फिर से बनाता है; केवल `de`: योजना के अंत तक; केवल `ate`: कल से उस तक), `periodo_livre` (मिटाकर दिनों को बिना ड्रॉ ख़ाली छोड़ता है; वही तारीख़ें), `manual` (मैनुअल रोटेशन: `de`, `ate`, `dias[YYYY-MM-DD]` = घड़ी का id, `auto` या `livre`), `apagar_tudo` (कल से आगे का पूरा कार्यक्रम हटता है; `hoje=1`: आज से आगे; मोड योजना दोबारा बनाता है), `resortear`, `resortear_hoje`, `proxima_semana`, `modo` (मोड बदलना) |
| `modos` | `salvar`, `ativar`, `excluir` |
| `arvore` | `novo`, `renomear`, `mover`, `ordem`, `excluir` (समूह) और `relogios` (हर घड़ी का समूह) |
| `campos`, `avisos`, `lancamento_tipos` | `novo`, `alterar`, `excluir` (और फ़ील्ड में `ordem`) |
| `formulas` | `nova`, `alterar`, `excluir` |
| `criterios` | `conjunto_criar`, `conjunto_excluir`, `param_novo`, `param_excluir`, `param_pesos`, `sub_novo`, `sub_excluir`, `sub_medida`, `sub_mover`, `sub_pesos`, `faixas`, `ordem`, `restaurar` |
| `config` | `salvar`, `evento_salvar`, `evento_excluir` |
| `destinos` | `salvar` (webhook, ई-मेल या Web Push, वह जो पाता है उसके साथ), `testar` (`qual=`: पाठ, सुबह या रात), `excluir` |
| `agendas` | `salvar` (Google, CalDAV, Outlook या ICS), `sincronizar`, `teste_criar`, `teste_remover`, `novo_link` (ICS), `outlook_conectar`, `excluir` |
| `usuarios` | `salvar` (`senha` के साथ, या किसी दूसरे सिस्टम के `senha_hash` के साथ; पहला उपयोगकर्ता, टोकन से, master है) और, केवल master: `alterar`, `oficializar`, `prorrogar`, `voltar_demo`, `bloquear`, `transferir`, `dar_acesso`, `excluir` |
| `conta` | `alterar` (ई-मेल, भाषा, पासवर्ड), `token_criar`, `token_revogar`, `aparelho_lembrar` (फ़ोन पर ऐप: इस डिवाइस की कुंजी एक कुकी में, जो बिना पासवर्ड प्रवेश करती है), `aparelho_esquecer`, `push_inscrever`, `push_cancelar`, `push_testar`, `excluir`; केवल master: `email_testar`, `vapid_gerar` |
| `colecoes` | `criar`, `renomear`, `excluir`, `arvore_copiar`, `arvore_renomear`, `arvore_excluir` |
| `acessos`, `convites`, `pedidos` | `remover`, `sair`; `convidar`, `aprovar`, `recusar`, `reenviar`, `cancelar`, `aceitar`; `pedir`, `aprovar`, `recusar`, `cancelar` |
| `cadastro` | बिना लॉगिन: `criar_conta`, `esqueci_senha`, `nova_senha` |
| `migracoes` | `aplicar` |
| `instalacao` | `instalar` (सिर्फ़ ख़ाली डेटाबेस पर, टोकन से) |
| `backup` | `fazer` (अभी एक बैकअप), `restaurar` (`arquivo=`: डेटाबेस को उस कॉपी पर लौटाता है, पहले अभी वाले की एक कॉपी रखकर) |
| `importacao` | `importar` (`banco=` पुराने डेटाबेस का नाम; `substituir=1` जब इस डेटाबेस में पहले से घड़ियाँ हों; बीच में रुका तो कुछ नहीं सहेजा जाता) |

**त्रुटि वाले जवाब** कारण बताते हैं: पूछताछ में `{"erro": "..."}`, और अस्वीकार हुए लेखन में
`{"ok": false, "mensagem", "erros": [...]}` (हर कारण की एक त्रुटि):

| कोड | कब |
|---|---|
| `400` | जाँचों ने लेखन अस्वीकार किया (त्रुटियाँ बताती हैं क्यों), या `calcular` में ग़लत लिखा सूत्र |
| `302` | सही लॉगिन और `volta=` के साथ `recurso=entrar`: पेज पर जाता है |
| `401` | न टोकन न साइट का लॉगिन, या दोनों ग़लत; कोई उपयोगकर्ता न हो (या डेटाबेस ख़ाली हो) तो जवाब बताता है कि क्या करें (`sem_usuarios` और `como`) |
| `403` | बैकअप और माइग्रेशन, ऐसे व्यक्ति द्वारा जो master नहीं है |
| `404` | अज्ञात संसाधन (उत्तर में संसाधनों की सूची आती है), वह संग्रह जिसे आप नहीं देखते, या वह घड़ी, फ़ोटो, दस्तावेज़ (या उसकी फ़ाइल) या `README.md` (`manual` में) जो मौजूद नहीं, या कोई फ़ाइल जो `codigo` से नहीं निकलती (जान-बूझकर बाहर रखी चीज़ों के लिए `o_que_ver` के साथ, जैसे `config.php`) |
| `409` | `confirmar=1` के बिना हटाना (`impacto` के साथ: जो साथ में जाता; कुछ नहीं हटा); बिना किसी संग्रह का खाता (`sem_colecao`); या डेटाबेस ने लिखना अस्वीकार किया: एक रिकॉर्ड जिसे कोई और अभी उपयोग करता है, दोहराया गया मान, या डेटाबेस के सत्यापन नियमों से बाहर का मान (नियम का नाम साथ आता है) |
| `413` | भेजना सर्वर के PHP की सीमा (`post_max_size`) से आगे गया: बहुत बड़ी फ़ाइल |
| `500` | सिस्टम रुका (`config.php` का `API_TOKEN` या `FUSO` अमान्य) या आंतरिक त्रुटि |
| `503` | पुराना डेटाबेस (सिर्फ़ `recurso=migracoes` और `migracoes`/`aplicar` लेखन जवाब देते हैं; और `manual` और `codigo`, जो डेटाबेस इस्तेमाल नहीं करते), ख़ाली डेटाबेस (इंस्टॉलेशन से पहले सिर्फ़ `instalacao` जवाब देता है, और `manual` और `codigo`) या डेटाबेस बंद |

### उपयोगकर्ता और पासवर्ड {#os-usuários-e-as-senhas}

पासवर्ड कहीं नहीं रखा जाता: सिर्फ़ उसका **हैश** रहता है, PHP के `password_hash` (bcrypt) से बना। हैश 60 अक्षरों का होता है,
`$2y$12$...`: `$2y$`, लागत (12 का मतलब 2¹² दौर), सॉल्ट (22 अक्षर, हर पासवर्ड के लिए यादृच्छिक) और नतीजा (31)। हैश वापस
पासवर्ड नहीं बनता: लॉगिन पर सिस्टम टाइप किए पासवर्ड का bcrypt हैश में मौजूद सॉल्ट और लागत से निकालकर तुलना करता है
(`password_verify`)। एक ही पासवर्ड हर बार अलग हैश देता है, और सब मेल खाते हैं।

**उपयोगकर्ताओं को किसी दूसरे सिस्टम में ले जाने के लिए**, हर एक का लॉगिन और `senha_hash` (`recurso=usuarios`, master द्वारा) ले जाएँ और वहाँ
टाइप किए पासवर्ड को उस भाषा के bcrypt से हैश से मिलाएँ: हर कोई अपने पुराने पासवर्ड से ही आता है। `$2y$` (PHP) और `$2b$`
(बाक़ी भाषाएँ) एक ही गणना हैं; अगर लाइब्रेरी `$2y$` अस्वीकार करे, तो शुरुआत `$2b$` कर दें। **दूसरे सिस्टम से उपयोगकर्ता लाने के
लिए**, वहाँ का हैश भेजें: `recurso=usuarios`, `acao=salvar`, `login` और `senha_hash` (bcrypt `$2y$`, `$2b$` या `$2a$`, या
argon2)।

| भाषा | पासवर्ड जाँचें | हैश बनाएँ |
|---|---|---|
| PHP | `password_verify($senha, $hash)` | `password_hash($senha, PASSWORD_DEFAULT)` |
| Python (`pip install bcrypt`) | `bcrypt.checkpw(senha.encode(), hash.encode())` | `bcrypt.hashpw(senha.encode(), bcrypt.gensalt()).decode()` |
| Node (`npm install bcryptjs`) | `require("bcryptjs").compareSync(senha, hash)` | `require("bcryptjs").hashSync(senha, 12)` |
| Java (Spring Security) | `new BCryptPasswordEncoder().matches(senha, hash)` | `new BCryptPasswordEncoder().encode(senha)` |
| C# (BCrypt.Net-Next) | `BCrypt.Net.BCrypt.Verify(senha, hash)` | `BCrypt.Net.BCrypt.HashPassword(senha)` |

```sh
# सूची: हर एक का लॉगिन और हैश
curl -u upyogkarta:password "http://server/relojoeiro/api.php?recurso=usuarios"

# दूसरे सिस्टम से एक उपयोगकर्ता लाएँ, उसके पुराने पासवर्ड के साथ
curl -u upyogkarta:password --data-urlencode recurso=usuarios --data-urlencode acao=salvar --data-urlencode login=maria \
     --data-urlencode 'senha_hash=$2b$12$...' http://server/relojoeiro/api.php
```

**सामान्य फ़िल्टर** किसी भी जवाब की किसी भी सूची पर काम करते हैं:

| पैरामीटर | क्या करता है |
|---|---|
| `incluir=relogios,plano` / `excluir=motor` | सिर्फ़ ये हिस्से, या इनके सिवा सब |
| `f[lista][campo]=v` | `v` के बराबर (या कई में से एक के: `v1,v2`) |
| `f[lista][campo][de]=` / `[ate]=` | संख्याओं या तारीख़ों की सीमा |
| `f[lista][campo][contem]=` / `[diferente]=` / `[vazio]=1` | टेक्स्ट, बहिष्कार, ख़ाली |
| `busca[lista]=texto` | किसी भी फ़ील्ड में टेक्स्ट |
| `ordem[lista]=-campo` | क्रम देता है (`-` उलट देता है) |
| `limite[lista]=20&pagina[lista]=2` | पेज बाँटना |
| `mostrar[lista]=id,nome` | सिर्फ़ ये फ़ील्ड |

अंदरूनी फ़ील्ड बिंदु से चलते हैं, जैसे `formulas.energia.valor`, `campos.loja.valor` और `compra.valor`। टेक्स्ट की तुलना बड़े-छोटे
अक्षरों और मात्राओं/एक्सेंट का फ़र्क़ किए बिना होती है।

📖 **पूरा दस्तावेज़ीकरण** (हर पूछताछ, उदाहरण के साथ हर लेखन और जवाबों के सभी फ़ील्ड का शब्दकोश) [`api.php`](api.php) के हेडर में
और ख़ुद API में है: `api.php?recurso=ajuda` (अनुरोध की भाषा में)। शब्दकोश का एक ही स्रोत है, सहायता वाला; हेडर वाला उसी से बनता
है (`php testes/dicionario.php --gravar`)।

---

## इंस्टॉलेशन {#instalação}

**ज़रूरतें:** `curl` और `openssl` (webhooks, ई-मेल, Web Push, कैलेंडर और एन्क्रिप्टेड रहस्यों के लिए) के साथ PHP 8.1+, एक वेब सर्वर (nginx या Apache), cron और
**इनमें से एक डेटाबेस**, हर एक अपने PHP एक्सटेंशन से:

| डेटाबेस | PHP एक्सटेंशन | संस्करण | कब चुनें |
|---|---|---|---|
| **SQLite** | `sqlite3` | 3.35+ (3.53 पर जाँचा) | सबसे आसान: एक ही फ़ाइल, बिना डेटाबेस सर्वर। निजी कलेक्शन के लिए काफ़ी से ज़्यादा, Raspberry Pi पर भी |
| **MySQL / MariaDB** | `mysqli` | utf8mb4 के साथ (MariaDB 11.4 पर जाँचा) | आपकी होस्टिंग में पहले से है, या आप पहले से इस्तेमाल करते हैं |
| **PostgreSQL** | `pgsql` | 12+, ICU के साथ, डिफ़ॉल्ट (18 पर जाँचा) | आपके पास पहले से एक Postgres चल रहा है |
| **Firebird** | `pdo_firebird` | 3.0+ (3.0.11 पर, PHP 8.4 के साथ जाँचा) | आपके पास पहले से एक Firebird चल रहा है |

सिस्टम चारों पर एक जैसा व्यवहार करता है। यह पैरिटी टेस्ट ([`testes/`](testes/)) जाँचता है, जो चारों डेटाबेस पर एक ही स्क्रिप्ट
चलाकर API की हर वापसी की तुलना करता है।

```sh
# 1. कोड
git clone https://github.com/Challado/relojoeiro.git /var/www/relojoeiro
cd /var/www/relojoeiro

# 2. सेटिंग्स: डेटाबेस (DB_TIPO), API टोकन, समय क्षेत्र, दस्तावेज़ों का फ़ोल्डर और, चाहें तो, ई-मेल, Web Push, Outlook और
#    खुले पंजीकरण के नियम (नीचे config.php की तालिका देखें)
cp config.exemplo.php config.php
nano config.php
#    दस्तावेज़ों का फ़ोल्डर (DOCUMENTOS_PASTA), प्रकाशित फ़ोल्डर के बाहर, PHP उपयोगकर्ता के लिखने लायक़:
#    mkdir -p /var/lib/relogios2/documentos && chown www-data /var/lib/relogios2/documentos

# 3. ख़ाली डेटाबेस (सिर्फ़ MySQL, Postgres और Firebird में; SQLite फ़ाइल ख़ुद बनाता है)
#    MySQL:    mysql -u root -p -e "CREATE DATABASE relogios2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
#    Postgres: createdb -U postgres relogios2
#    Firebird: echo "CREATE DATABASE 'localhost:/var/lib/firebird/3.0/data/relogios2.fdb' USER 'SYSDBA' PASSWORD '...'
#                    PAGE_SIZE 16384 DEFAULT CHARACTER SET UTF8;" | isql-fb -q

# 4. इंस्टॉलेशन: संरचना और शुरुआती सेट, config.php के डेटाबेस के लिए बदले हुए
php instalar.php --lingua=hi
#    शुरुआती सेट उस भाषा में आता है, और वही इंस्टॉलेशन की डिफ़ॉल्ट बन जाती है (--lingua के बिना: पुर्तगाली)
#    या API से, config.php के टोकन के साथ (भाषा: lingua=hi, किसी भी अनुरोध की तरह):
#    curl -H "X-Api-Token: $TOKEN" -d recurso=instalacao -d acao=instalar -d lingua=hi http://server/relojoeiro/api.php

# 5. पहला उपयोगकर्ता, master, अपने संग्रह के साथ (पासवर्ड बिना दिखे पूछा जाता है)
php criar_usuario.php aapka_login
#    या API से, टोकन के साथ:
#    curl -H "X-Api-Token: $TOKEN" -d recurso=usuarios -d acao=salvar -d login=aapka_login -d senha=... http://server/relojoeiro/api.php

# 6. cron, हर मिनट (यह चुप रहता है: कुछ नहीं लिखता और ईमेल नहीं भेजता)
( crontab -l; echo "* * * * * php /var/www/relojoeiro/cron.php" ) | crontab -
```

`cron.php` के दो विकल्प हैं, हाथ से चलाने के लिए: `--forcar` सुबह और शाम के दौर अभी चलाता है, भले आज पहले चल चुके हों
(शेड्यूल बनाता है, संदेश भेजता है और कैलेंडर सिंक करता है, अगर वे चालू हों), और `-v` स्क्रीन पर वह लॉग दिखाता है जो वह सहेजता है
(cron के रन पेज वाला ही)। उदाहरण: `php cron.php --forcar -v`।

SQLite में डेटाबेस की फ़ाइल (`DB_ARQUIVO`) **वेब सर्वर के प्रकाशित फ़ोल्डर के बाहर** रखें, ऐसे फ़ोल्डर में जिसमें PHP उपयोगकर्ता
लिख सके (SQLite बगल में `-wal` और `-shm` फ़ाइलें बनाता है)।

Firebird में डेटाबेस **UTF8** में बनना चाहिए (ऊपर का `DEFAULT CHARACTER SET UTF8`)। PHP को `pdo_firebird` एक्सटेंशन चाहिए:
Debian और Ubuntu में `php8.4-interbase` पैकेज (उसका पुराना नाम) और Firebird क्लाइंट (`libfbclient2`), जो साथ आता है; `php.ini`
में `extension=pdo_firebird`। सर्वर, `firebird3.0-server` पैकेज (या Firebird की साइट का इंस्टॉलर)। `config.php` में `DB_NOME`
**डेटाबेस सर्वर पर** `.fdb` फ़ाइल का पाथ है (या `databases.conf` का उपनाम), और `DB_USUARIO`/`DB_SENHA` एक Firebird उपयोगकर्ता
(`SYSDBA` या सिस्टम के लिए बनाया गया)।

**7. वेब सर्वर।** वेब से सिर्फ़ पेज, `api.php` और जो वे लोड करते हैं (`estilo.css`, `.js`, छवियाँ) खुल सकते हैं। बाक़ी सब बंद
रहता है: कोर (`lib.php`, `banco.php`, `operacoes.php`), `config.php`, cron, कमांड लाइन की स्क्रिप्ट, टेस्ट, `.sql`, `.json`,
`.md` और `.sqlite` फ़ाइलें, और बिंदु से शुरू होने वाला सब कुछ, `.git` फ़ोल्डर समेत, जो `git clone` के साथ आता है। प्रोजेक्ट
दोनों सर्वरों के लिए यह तैयार लाता है:

- **Apache:** [`.htaccess`](.htaccess) फ़ोल्डर में पहले से है और अपने-आप काम करता है। फ़ोल्डर को Apache की सेटिंग्स में सिर्फ़
  `AllowOverride All` चाहिए, जो लगभग हर होस्टिंग में डिफ़ॉल्ट है; इसके बिना `.htaccess` अनदेखा होता है और कुछ भी बंद नहीं
  होता। यह तब भी साइट का लॉगिन चलाता है जब PHP FastCGI (PHP-FPM, ज़्यादातर होस्टिंग) से चलता है, जिस हालत में Apache पासवर्ड
  PHP तक नहीं पहुँचाता।
- **nginx:** [`nginx-relogios.conf`](nginx-relogios.conf) का ब्लॉक अपनी साइट के `server` में कॉपी करें (`/relojoeiro/` और
  PHP-FPM का पता ठीक करें)। इसमें दस्तावेज़ भेजने के लिए `client_max_body_size 512m` भी है (देखें
  [भेजने का आकार](#o-tamanho-dos-envios-os-documentos))।

जाँचने के लिए `https://aapka-server/relojoeiro/config.php` और `https://aapka-server/relojoeiro/.git/config` खोलें: दोनों को
**403** देना चाहिए।

**8. ब्राउज़र में खोलें** और बनाए गए उपयोगकर्ता से लॉग इन करें। शुरुआती सेट पहले से वहाँ है: **आज → नई घड़ी** में अपनी
घड़ियाँ दर्ज करें, हर एक का समूह चुनें और फ़ील्ड भरें। **मेरा खाता** में अपना ई-मेल डालें। दूसरे लोग **खाता बनाएँ**
(`cadastro.php`) से, आपके निमंत्रण से आते हैं, या master **उपयोगकर्ता** में खाता बनाता है।

### `config.php` {#o-configphp}

| स्थिरांक | किसलिए |
|---|---|
| `DB_TIPO` | डेटाबेस: `mysql` (डिफ़ॉल्ट, MariaDB के लिए भी), `pgsql`, `sqlite` या `firebird` |
| `DB_HOST`, `DB_PORTA`, `DB_NOME`, `DB_USUARIO`, `DB_SENHA` | डेटाबेस सर्वर (MySQL, Postgres और Firebird); `DB_PORTA` 0 डिफ़ॉल्ट पोर्ट है। Firebird में `DB_NOME` सर्वर पर `.fdb` फ़ाइल का पाथ है (उदा.: `/var/lib/firebird/3.0/data/relogios2.fdb`) या `databases.conf` का उपनाम, और ख़ाली `DB_HOST` फ़ाइल को सीधे, बिना सर्वर के खोलता है (एम्बेडेड Firebird) |
| `DB_ARQUIVO` | डेटाबेस की फ़ाइल (SQLite), प्रकाशित फ़ोल्डर के बाहर |
| `API_TOKEN` | **ज़रूरी**, 10 या ज़्यादा अक्षरों का। इसके बिना पूरा सिस्टम रुक जाता है और कारण बताता है। |
| `FUSO` | सिस्टम का समय क्षेत्र, जैसे `America/Sao_Paulo`। ख़ाली या न हो तो: PHP वाला (php.ini में `date.timezone`)। यह डेटाबेस में सहेजी तारीख़ों का और जिसने दूसरा नहीं चुना उसका समय-क्षेत्र है; हर संग्रह (सेटिंग्स) और हर व्यक्ति (मेरा खाता) अपना चुन सकता है (देखें [समय-क्षेत्र](#o-fuso-horário)) |
| `SEGREDO_CHAVE` | वैकल्पिक: वह कुंजी जो डेटाबेस में CalDAV के पासवर्ड, Google की कुंजियाँ, Outlook के टोकन और webhooks के हेडर एन्क्रिप्ट करती है। इसके बिना, `API_TOKEN` (कुंजी बदलने से सहेजे रहस्य खो जाते हैं: बस फिर से लिखें) |
| `MSG_TITULO` | संदेशों का शीर्षक (webhooks का `{titulo}`, ई-मेल का विषय, Web Push का शीर्षक)। डिफ़ॉल्ट: Relógios |
| `MSG_ENDPOINT`, `MSG_DESTINATARIO` | केवल माइग्रेशन v25 के लिए: पुरानी संदेश API (Telegram) संग्रह का पहला webhook गंतव्य बनती है |
| `CADASTRO_ABERTO`, `DEMO_DIAS`, `CONVITE_DIAS` | वैकल्पिक: खुला पंजीकरण (`false`: बंद, केवल निमंत्रण से; पंक्ति के बिना, खुला), डेमो खाते के दिन (डिफ़ॉल्ट 30; `0`: खाता आधिकारिक ही बनता है) और निमंत्रण या अनुरोध कितने दिन प्रतीक्षा करता है (डिफ़ॉल्ट 7) |
| `EMAIL_METODO`, `EMAIL_REMETENTE`, `SMTP_HOST`, `SMTP_PORTA`, `SMTP_SEGURANCA`, `SMTP_USUARIO`, `SMTP_SENHA` | ई-मेल: `smtp` (दिया गया सर्वर; सुरक्षा `tls`, डिफ़ॉल्ट, `ssl` या `nenhuma`) या `mail` (PHP का `mail()`), और प्रेषक। किसी के बिना, कोई ई-मेल नहीं जाता |
| `IMAP_HOST`, `IMAP_PORTA`, `IMAP_SEGURANCA`, `IMAP_USUARIO`, `IMAP_SENHA`, `IMAP_PASTA_ENVIADOS` | वैकल्पिक: भेजे गए हर ई-मेल की एक प्रति खाते के Sent फ़ोल्डर में |
| `POP_HOST`, `POP_PORTA`, `POP_SEGURANCA`, `POP_USUARIO`, `POP_SENHA` | वैकल्पिक: केवल ई-मेल परीक्षण के लिए POP3 से मेलबॉक्स जाँचने हेतु |
| `VAPID_PUBLICA`, `VAPID_PRIVADA`, `VAPID_ASSUNTO` | Web Push: कुंजियों की जोड़ी (master **मेरा खाता** में बनाता है) और विषय (`mailto:...`) |
| `OUTLOOK_CLIENTE_ID`, `OUTLOOK_CLIENTE_SEGREDO` | Microsoft में पंजीकृत ऐप, Outlook कैलेंडरों के लिए (नीचे देखें) |
| `DOCUMENTOS_PASTA` | दस्तावेज़ों की फ़ाइलों (मैनुअल, बिल, फ़ोटो, वीडियो) का फ़ोल्डर, **प्रकाशित फ़ोल्डर के बाहर**, PHP उपयोगकर्ता के लिखने लायक़। इसके बिना दस्तावेज़ पेज बताता है कि यह नहीं है और भेजना स्वीकार नहीं करता। उदा.: `define("DOCUMENTOS_PASTA", "/var/lib/relogios2/documentos");` |
| `BACKUP_PASTA`, `BACKUP_MANTER`, `BACKUP_DOCUMENTOS` | वैकल्पिक: डेटाबेस का बैकअप (देखें [बैकअप](#cópia-de-segurança))। फ़ोल्डर, **प्रकाशित फ़ोल्डर के बाहर** (इसके बिना अपने-आप कॉपी नहीं होती); कितनी रखी जाती हैं (डिफ़ॉल्ट 7); और क्या डेटाबेस में रखी दस्तावेज़ों की सामग्री साथ जाती है (डिफ़ॉल्ट: नहीं)। |
| `BACKUP_ATIVO` | वैकल्पिक: `false` बैकअप बंद करता है: न अपने-आप कॉपी, न **अभी कॉपी बनाएँ**, न सेटिंग्स में **बैकअप** बॉक्स (फ़ोल्डर न होने की चेतावनी भी नहीं)। इसके बिना: चालू |
| `DOCUMENTOS_LIMITE` | वैकल्पिक: स्वीकार होने वाला सबसे बड़ा दस्तावेज़, बाइट में। इसके बिना 100 MB (`104857600`); `0` या `-1`, सिस्टम की कोई सीमा नहीं। पुराना नाम `MANUAL_LIMITE` तब भी चलता है जब यह न हो। PHP और nginx की सीमाएँ लागू रहती हैं (देखें [भेजने का आकार](#o-tamanho-dos-envios-os-documentos))। उदा.: 500 MB के लिए `define("DOCUMENTOS_LIMITE", 524288000);` |
| `ANTIGO_HOST`, `ANTIGO_PORTA`, `ANTIGO_USUARIO`, `ANTIGO_SENHA` | वैकल्पिक, सिर्फ़ तब `importar.php` के लिए जब यह सिस्टम Postgres, SQLite या Firebird पर हो: पिछले सिस्टम का MySQL कहाँ है |
| `ALEATORIO_RANDOMORG_URL`, `ALEATORIO_ANU_URL` | वैकल्पिक, सिर्फ़ टेस्ट के लिए: यादृच्छिक नंबरों के स्रोतों के पते (`https://www.random.org/integers/` और `https://qrng.anu.edu.au/API/jsonI.php`)। बदलने पर ड्रॉ नंबर एक टेस्ट सर्वर से माँगते हैं |
| `GOOGLE_TOKEN_URL`, `GOOGLE_API_URL`, `OUTLOOK_LOGIN_URL`, `OUTLOOK_GRAPH_URL` | वैकल्पिक, केवल परीक्षण के लिए: कैलेंडरों द्वारा उपयोग किए जाने वाले Google और Microsoft के पते। बदलने पर, सिंक एक परीक्षण सर्वर से बात करता है |
| `TEXTOS_ESTRITO` | वैकल्पिक, अनुवाद करने वाले के लिए: `true` से कमी वाला अनुवाद त्रुटि के साथ रुक जाता है (देखें [भाषाएँ](#os-idiomas)) |

सिस्टम अपने फ़ोल्डर का `config.php` पढ़ता है। सर्वर का एक पर्यावरण चर, `RELOGIOS_CONFIG`, उसकी जगह कोई दूसरी फ़ाइल बताता है:
पैरिटी टेस्ट यही इस्तेमाल करता है (`testes/paridade.sh`, [`testes/config.teste.php`](testes/config.teste.php) के साथ), ताकि असली
`config.php` को छुए बिना चले। सामान्य इंस्टॉलेशन में इसे न लगाएँ।

### सर्वर पर गंतव्य और कैलेंडर {#destinos-e-agendas-no-servidor}

हर संग्रह अपने गंतव्य और कैलेंडर **गंतव्य** और **कैलेंडर** पृष्ठों पर बनाता है। सर्वर पर जिनकी कॉन्फ़िगरेशन चाहिए:

- **Telegram (एक webhook):** @BotFather से एक बॉट बनाएँ (वह टोकन देता है) और अपना `chat_id` पता करें (बॉट को एक संदेश भेजें
  और `https://api.telegram.org/bot<token>/getUpdates` खोलें)। **गंतव्य** में, एक webhook: विधि `GET` या `POST`, पता
  `https://api.telegram.org/bot<token>/sendMessage`, फ़ील्ड `chat_id` = वह संख्या और `text` = `{mensagem}`। कोई भी दूसरा ब्रिज
  (n8n, Home Assistant, आपका अपना पता) वैसे ही काम करता है: सिस्टम `{titulo}` और `{mensagem}` वहाँ भेजता है जहाँ आपने रखे,
  और उत्तर 2xx हो तो भेजा हुआ मानता है। लंबे संदेश हिस्सों में बाँटे जाते हैं।
- **ई-मेल:** `config.php` में SMTP सर्वर (`SMTP_HOST`, पोर्ट, सुरक्षा, उपयोगकर्ता और पासवर्ड; Gmail और Outlook में, एक ऐप
  पासवर्ड) और `EMAIL_REMETENTE`; या `EMAIL_METODO = "mail"`, सर्वर पर sendmail के साथ। `IMAP_*` के साथ, भेजे गए हर ई-मेल की
  एक प्रति Sent में रहती है। master **मेरा खाता › ई-मेल सर्वर** में परीक्षण करता है: बॉक्स दिखाता है कि सिस्टम ने `config.php` से क्या पढ़ा (तरीका, प्रेषक, SMTP, IMAP
  और POP; पासवर्ड केवल *सेट* है या नहीं) और बताता है कि क्या गलत है या कमी है (`EMAIL_METODO` नहीं, पोर्ट जो सुरक्षा से मेल नहीं खाता, खाली
  पासवर्ड, `openssl` के बिना PHP...)। **कनेक्शन जाँचें** SMTP, IMAP (और भेजे गए फ़ोल्डर की जाँच) और POP में कुछ भेजे बिना लॉग इन करता है;
  **एक जाँच ई-मेल भेजें** आपके लिखे पते पर सचमुच भेजता है। फिर हर भाग का परिणाम और **सर्वरों से बातचीत** आती है, पंक्ति दर पंक्ति
  (पासवर्ड के बिना), ताकि पता चले कि कहाँ रुका।
- **Web Push:** साइट **https** से खुलनी चाहिए। master **मेरा खाता › Web Push कुंजियाँ** में कुंजियाँ बनाता है और दोनों
  पंक्तियाँ `config.php` में कॉपी करता है। फिर हर व्यक्ति **मेरा खाता** में अपने उपकरण पर सूचनाएँ चालू करता है (iPhone पर,
  पहले साइट को होम स्क्रीन पर जोड़ें), और एक Web Push गंतव्य उन लोगों के उपकरणों पर भेजता है जिनकी ओर वह इंगित करता है।
- **Google कैलेंडर:** Google Cloud में एक **सेवा खाता** बनाएँ और JSON कुंजी डाउनलोड करें; अपना कैलेंडर सेवा खाते के ई-मेल के
  साथ, इवेंट बदलने की अनुमति सहित साझा करें; **कैलेंडर** में कुंजी का JSON (वह डेटाबेस में एन्क्रिप्टेड रहता है) और कैलेंडर
  ID चिपकाएँ। केवल master, JSON की जगह, सर्वर पर किसी फ़ाइल का पथ दे सकता है।
- **CalDAV (Nextcloud, iCloud, Fastmail):** कैलेंडर का पता, उपयोगकर्ता और पासवर्ड। **iCloud** में, एक **ऐप-विशिष्ट पासवर्ड**
  (appleid.apple.com › ऐप-विशिष्ट पासवर्ड) और `caldav.icloud.com` पर कैलेंडर का पता।
- **Outlook:** Microsoft के पोर्टल (**Entra ID › ऐप पंजीकरण**) में एक ऐप पंजीकृत करें, `Calendars.ReadWrite` अनुमति और वापसी
  पते `https://<आपका सर्वर>/relojoeiro/api.php?recurso=outlook_retorno` के साथ (कैलेंडर पृष्ठ सटीक पता दिखाता है); ID और
  रहस्य `config.php` में डालें (`OUTLOOK_CLIENTE_ID` और `OUTLOOK_CLIENTE_SEGREDO`)। **कैलेंडर** में, **Outlook से जोड़ें**
  Microsoft के पृष्ठ पर ले जाता है और जुड़कर लौटता है। ऐप के बिना, ICS लिंक का उपयोग करें: Outlook उसकी सदस्यता लेता है।
- **ICS लिंक:** कुछ कॉन्फ़िगर नहीं करना। कैलेंडर किसी भी ऐप में सदस्यता के लिए एक गुप्त लिंक देता है (Google: **अन्य कैलेंडर
  › URL से**; Outlook: **वेब से सदस्यता लें**; iPhone: **सदस्यता वाला कैलेंडर**); **लिंक बदलें** पिछले को बंद कर देता है।

master के संग्रहों के बाहर, कोई जो पते लिखता है (webhook, CalDAV) वे इंटरनेट पर होने चाहिए: सिस्टम सर्वर के अपने नेटवर्क के
पते (`127.0.0.1`, `192.168.x`...) अस्वीकार करता है, ताकि कोई सर्वर से उसके नेटवर्क की जाँच न कर सके।

### भेजने का आकार (दस्तावेज़) {#o-tamanho-dos-envios-os-documentos}

हर दस्तावेज़ एक बार में ऊपर जाता है (साथ चुनी गई कई फ़ाइलें एक-एक करके जाती हैं), और सर्वर की तीन सीमाएँ बड़ी फ़ाइल को सिस्टम से
पहले ही अस्वीकार कर सकती हैं। सिस्टम हर फ़ाइल **100 MB** तक स्वीकार करता है; `config.php` का `DOCUMENTOS_LIMITE` इसे बदलता है
(बाइट में; `0` या `-1`: सिस्टम की कोई सीमा नहीं)। फ़ोन के वीडियो के लिए लगभग 500 MB सोचें:

| कहाँ | सीमा | डिफ़ॉल्ट | 500 MB के लिए |
|---|---|---|---|
| `php.ini` | `upload_max_filesize` (फ़ाइल) और `post_max_size` (पूरा भेजना) | 2M और 8M | `upload_max_filesize = 500M` और `post_max_size = 512M` |
| nginx | `client_max_body_size` | 1m | `512m` ([`nginx-relogios.conf`](nginx-relogios.conf) में पहले से) |
| `config.php` | `DOCUMENTOS_LIMITE` | 100 MB | `524288000` |

फ़ाइल सीधे दस्तावेज़ों के फ़ोल्डर में जाती है; डेटाबेस की कॉपी 4 MB के टुकड़ों में जाती है, एक बार में एक पढ़ा जाता है: PHP का
`memory_limit` और MySQL का `max_allowed_packet` (16 MB या ज़्यादा) फ़ाइल का आकार सीमित नहीं करते। दस्तावेज़ पेज वह सीमा दिखाता
है जो सचमुच लागू है (सिस्टम वाली, या `php.ini` की वजह से कम; कोई सीमा न हो तो "किसी भी आकार की")। उससे बड़ी फ़ाइल आकार के संदेश
के साथ अस्वीकार होती है; `post_max_size` से बड़ा भेजना कोड `413` और सर्वर की सीमा के साथ लौटता है। Apache में `php.ini` के
अलावा छूने को कोई सीमा नहीं है।

### डेटाबेस के अपडेट {#atualizações-do-banco}

कोड अपडेट करने के बाद, अगर कोई नया माइग्रेशन (`migracao_v*.sql`) है, तो API `503` लौटाती है और **सेटिंग्स** पृष्ठ उसे लागू
करने का बटन दिखाता है (केवल master लागू करता है)। cron भी रुकता है और कारण दर्ज करता है, आधा चलने के बजाय। नए इंस्टॉलेशन
में, `schema.sql` पहले से सभी माइग्रेशनों की संरचना लाता है, और प्रारंभिक सेट `modelo.json` से आता है, जब पहला उपयोगकर्ता
बनाया जाता है।

**माइग्रेशन v25 (कई लोग)** पूरा डेटाबेस फिर बनाता है: पहले सब कुछ की एक प्रति `relojoeiro-antes-da-v25.jsonl.gz` में रखता है
(`BACKUP_PASTA` में, या अस्थायी फ़ोल्डर में), नई संरचना बनाता है और हर रिकॉर्ड को नए रूप में, उन्हीं संख्याओं के साथ लौटाता है
(घड़ियाँ, दस्तावेज़ और प्रविष्टियाँ अपने पुराने id के साथ रहती हैं)। सबसे पुराना उपयोगकर्ता master और संग्रह व वृक्ष का स्वामी
बनता है; बाकी, संग्रह के सदस्य। Telegram (`MSG_ENDPOINT`) पहला webhook गंतव्य बनता है, और Google कैलेंडर, पहला कैलेंडर। अगर
यह बीच में रुक जाए, तो वही बटन प्रति से फिर करता है।

`schema.sql` और हर माइग्रेशन **चारों डेटाबेस के लिए एक ही फ़ाइल** हैं: संरचना (`CREATE TABLE`, `ALTER TABLE`) MySQL की बोली में
लिखी जाती है, और [`banco.php`](banco.php) उसे Postgres, SQLite और Firebird के लिए बदलता है (`AUTO_INCREMENT`, `ENUM`, `TINYINT`,
`DATETIME`, तालिका के भीतर के `INDEX`, `AFTER` और `MODIFY COLUMN`, जो SQLite में तालिका दोबारा बनाता है)। डेटा (`INSERT`,
`UPDATE`) चारों में साझा SQL में जाता है: `GROUP_CONCAT`, `IF()` या `FIND_IN_SET` नहीं। `INSERT IGNORE`, `REPLACE INTO`, `NOW()`,
`LEAST`, `GREATEST`, `LENGTH`, `DATE()`, `<=>`, `LIMIT`/`OFFSET`, बिना `FROM` का `SELECT`, कई पंक्तियों वाला `INSERT` और
`CONCAT` चल सकते हैं (`CONCAT` बिना NULL तर्क के: Postgres में NULL ख़ाली टेक्स्ट बन जाता है, MySQL में नतीजा NULL)। माइग्रेशन
v2 से v8 इससे पहले के हैं और सिर्फ़ पुराने MySQL डेटाबेस अपडेट करने के लिए हैं: Postgres, SQLite या Firebird डेटाबेस मौजूदा
संस्करण में ही बनता है।

दो कमांड जो किसी डेटाबेस में ऐसे मौजूद नहीं, `banco.php` समझता है और हर एक के तरीक़े से करता है:

| कमांड | क्या करता है |
|---|---|
| `ALTER TABLE t ALTER FOREIGN KEY (coluna) REFERENCES outra(id) ON DELETE CASCADE` (या `SET NULL`) | कॉलम की फ़ॉरेन की बदलता है (या न हो तो बनाता है)। MySQL, Postgres और Firebird में कैटलॉग में पुरानी की का नाम ढूँढकर उसे हटाता है और नई बनाता है (Firebird में एक ट्रांज़ैक्शन में); SQLite में तालिका दोबारा बनाता है। |
| `ALTER TABLE t DROP COLUMN coluna` | कॉलम को उसकी फ़ॉरेन की और इंडेक्स के साथ हटाता है (SQLite तालिका दोबारा बनाता है; Firebird पहले उस कॉलम वाले नियम भी हटाता है)। |
| `ALTER TABLE t ADD CONSTRAINT nome CHECK (condição)` | नाम वाला सत्यापन नियम; उस नाम का कोई पहले से हो तो बदल दिया जाता है। मौजूदा पंक्तियों को नया नियम मानना होगा, वरना डेटाबेस अस्वीकार करता है और पुराना नियम रहता है (Postgres और Firebird में एक ट्रांज़ैक्शन में; MySQL में नया पहले एक अस्थायी नाम से आता है; SQLite में तालिका एक ट्रांज़ैक्शन में दोबारा बनती है)। |

और, ताकि बीच में रुका माइग्रेशन उसी बटन से फिर चल सके: पहले से मौजूद कॉलम का `ADD COLUMN` और पहले से मौजूद इंडेक्स के नाम वाला
`ADD INDEX` कुछ नहीं करते।

किसी माइग्रेशन में **PHP का एक क़दम** भी हो सकता है, उसके लिए जो चारों का साझा SQL नहीं करता (v16 किसी फ़ील्ड के विकल्पों का
टेक्स्ट, एक प्रति पंक्ति, `campo_opcao` तालिका की पंक्तियों में बाँटता है; v20, MySQL/MariaDB पर, दूसरे collation में बनी
तालिकाओं को `utf8mb4_unicode_ci` में बदलता है)। यह SQL के ठीक बाद चलता है, सेटिंग्स के बटन से, और `instalar.php` में भी।

### डेटाबेस के बीच अंतर जो आप देख सकते हैं {#diferenças-entre-os-bancos-que-você-pode-notar}

- **MySQL/MariaDB में टेक्स्ट का क्रम:** हर तालिका `utf8mb4_unicode_ci` में बनती है, डेटाबेस का डिफ़ॉल्ट जो भी हो। MariaDB
  `utf8mb4_general_ci` के साथ आता है, जो बाक़ी डेटाबेस से अलग क्रम देता है (`dias_desde_compra` को `dias_de_carga` से पहले रखता
  है); इस तरह बना पुराना इंस्टॉलेशन माइग्रेशन v20 बदल देता है।
- **बड़े-छोटे अक्षर और एक्सेंट:** MySQL की तरह, "Relógio" और "RELOGIO" खोज, क्रम और लॉगिन में एक ही नाम हैं। Postgres में यह
  एक ICU collation (`ci`) से आता है जो `instalar.php` बनाता है। SQLite में PHP के एक फ़ंक्शन से; इसलिए किसी बाहरी प्रोग्राम
  (कमांड लाइन का `sqlite3`, DB Browser) में इन कॉलमों पर क्रम या तुलना करने से "no such collation sequence: ci" त्रुटि आती है।
  पढ़ना और निर्यात सामान्य रूप से चलते हैं। Firebird में सभी टेक्स्ट कॉलमों पर `UNICODE_CI_AI` collation से।
- **Firebird में:**
  - लंबे टेक्स्ट (`schema.sql` में `TEXT`: सूत्र, संदेश, cron का लॉग) **8191 अक्षरों** तक जाते हैं (MySQL में 65535 बाइट),
    क्योंकि वे `VARCHAR(8191)` बन जाते हैं (टेक्स्ट का `BLOB` PHP में null की तरह ख़ाली लौटता है); बिना सीमा का मुक्त
    टेक्स्ट (`LONGTEXT`: सर्विस की टिप्पणी) टेक्स्ट का `BLOB` बनता है;
  - 31 अक्षरों से लंबे इंडेक्स और नियम के नाम अंत में पूरे नाम के md5 का सिर्फ़ एक टुकड़ा रखते हैं, और `no` तालिका आरक्षित शब्द
    है: बाहरी प्रोग्राम (`isql-fb`, FlameRobin) में `"NO"` लिखें, उद्धरण चिह्नों में और बड़े अक्षरों में;
  - कई कॉलमों वाली अद्वितीय कुंजी जिसमें एक कॉलम खाली रह सकता है, एक व्यंजक पर अद्वितीय इंडेक्स बन जाती है, क्योंकि
    Firebird में एक ही मान और दूसरे कॉलम खाली वाली दो पंक्तियाँ दोहराई गई मानी जाती हैं (MySQL में नहीं);
  - `NOW()` का समय PHP से आता है (Firebird में प्रति कनेक्शन समय क्षेत्र नहीं होता), `config.php` के `FUSO` में, बाक़ी की तरह।
- **कॉलमों का क्रम** `AFTER` वाले माइग्रेशन में सिर्फ़ MySQL में लागू होता है: बाक़ी में नया कॉलम तालिका के अंत में जाता है (API के
  JSON में सिर्फ़ कुंजियों का क्रम बदलता है)।
- **id** हर डेटाबेस में अलग नंबर छोड़ सकते हैं (MySQL कुछ `INSERT ... SELECT` में id ब्लॉक में आरक्षित करता है)।

### पिछले सिस्टम से आना {#vindo-do-sistema-anterior}

`php importar.php <purana_database>` (या, API से, `recurso=importacao`, `acao=importar`, `banco=<purana_database>`) पेड़, घड़ियाँ,
फ़ोटो, उपयोगकर्ता, फ़ील्ड और पूरा इतिहास (प्रविष्टियों में बदला हुआ) एक ही ट्रांज़ैक्शन में लाता है: बीच में रुके तो कुछ नहीं सहेजा
जाता। `--substituir` (API में `substituir=1`) के साथ, पहले इस डेटाबेस में जो है उसे मिटाता है। [`PORTE.md`](PORTE.md) बिंदु-दर-बिंदु
दर्ज करता है कि पुराने सिस्टम का हर व्यवहार कैसे लाया गया।

---

<!-- parte 4 -->
## भीतर से यह कैसा है {#como-é-por-dentro}

- **शुद्ध PHP 8.1+, SQLite, MySQL/MariaDB, PostgreSQL या Firebird के साथ।** न फ़्रेमवर्क, न Composer, न निर्भरताएँ, ताकि PHP वाली
  किसी भी होस्टिंग पर चले। हर डेटाबेस अपने PHP एक्सटेंशन से (`sqlite3`, `mysqli`, `pgsql`, `pdo_firebird`); सिस्टम का SQL एक
  ही बार लिखा जाता है और [`banco.php`](banco.php) एक डेटाबेस से दूसरे में जो बदलता है उसका अनुवाद करता है।
- **अपना सूत्र इंजन**, सिंटैक्स विश्लेषण, इतिहास वाले फ़ंक्शन, समूह के अनुसार संस्करण और सूत्रों के बीच निर्भरताओं के साथ।
- **सिमुलेशन:** शेड्यूल और पूर्वानुमान भविष्य की प्रविष्टियाँ सिमुलेट करते हैं और दिन-ब-दिन सूत्र दोबारा गणना करते हैं, कुछ
  सहेजे बिना।
- **शुद्ध HTML और JavaScript में फ़्रंट-एंड।** हर PHP पेज सिर्फ़ ढाँचा देता है; उसका `.js` API को बुलाकर स्क्रीन बनाता है।
  **लॉगिन से पहले कुछ नहीं जाता:** पेज पहली पंक्ति से पहले सर्वर पर उपयोगकर्ता और पासवर्ड जाँचता है (`lib.php` में `pagina_login()`,
  जिसे `topo()` बुलाता है) और, उनके बिना, ब्राउज़र के पासवर्ड संवाद के साथ 401 देता है, बिना मेनू या ढाँचे के (रद्द करने पर केवल
  «लॉग इन» दिखता है, **फिर से कोशिश करें** और **खाता बनाएँ** लिंक के साथ)। लॉगिन पेज और API दोनों के लिए एक साथ चलता है (वही
  realm)। बिना लॉगिन केवल **खाता बनाएँ** और, कोई उपयोगकर्ता बनने से पहले, इंस्टॉलेशन के पेज खुलते हैं। स्क्रिप्ट URL में फ़ाइल की तारीख़ रखती हैं,
  ताकि ब्राउज़र का कैश पुराना संस्करण न दे।
- **चुप और खोजने योग्य cron:** हर मिनट चलता है, आउटपुट में कुछ नहीं लिखता और हर रन डेटाबेस में दर्ज करता है (बिना गतिविधि के
  7 दिन; गतिविधि या त्रुटि के साथ 1 साल)। गंभीर त्रुटि डेटाबेस में जाती है या, अगर डेटाबेस भी जवाब न दे, एक अस्थायी फ़ाइल में।
- **खोने से बचे दस्तावेज़:** हर फ़ाइल फ़ोल्डर में रहती है और, अगर माँगा जाए (सिस्टम, घड़ी या फ़ाइल द्वारा), पूरी, डेटाबेस में भी;
  cron जो ग़ायब हो उसे दोबारा बनाता है, जो कम हो उसे कॉपी करता है और जो ज़्यादा हो उसे साफ़ करता है (देखें
  [घड़ी के दस्तावेज़](#os-documentos-do-relógio))।
- **ख़ुद की रक्षा करने वाला डेटाबेस:** रिलेशनल और नॉर्मलाइज़्ड, चारों डेटाबेस में फ़ॉरेन की और सत्यापन नियमों (`CHECK`,
  `NOT NULL`, `UNIQUE`) के साथ: ग़लत लेखन डेटाबेस ख़ुद अस्वीकार करता है (देखें [डेटाबेस का मॉडल](#o-modelo-do-banco))।
- **ग़लती-रोधी:** मान्य टोकन के बिना या पुराने डेटाबेस के साथ, सिस्टम आधा चलने के बजाय रुककर कारण बताता है।
- **कई लोग, डेटाबेस द्वारा अलग:** हर अनुरोध एक संग्रह में मान्य होता है, और डेटाबेस की परत हर क्वेरी को फिर से लिखती है
  ताकि वह केवल उसी (और उसके वृक्ष) को देखे; किसी स्क्रीन या संसाधन को फ़िल्टर करना याद नहीं रखना पड़ता। जिस संग्रह को आप
  नहीं देखते वह 404 देता है, मानो मौजूद ही न हो।
- **छह भाषाएँ:** हर टेक्स्ट `t()` से गुज़रता है, पुर्तगाली को कुंजी बनाकर और हर भाषा की एक फ़ाइल के साथ (देखें
  [भाषाएँ](#os-idiomas))।

### डेटाबेस का मॉडल {#o-modelo-do-banco}

डेटाबेस संबंधपरक और सामान्यीकृत है (माइग्रेशन v16 से; v25 से, 60 तालिकाओं में): हर चीज़ एक तालिका में, तालिकाओं के बीच
हर संबंध एक **विदेशी कुंजी**, और कोई सूची किसी फ़ील्ड में पाठ के रूप में नहीं रखी जाती। खाते (`usuario`), संग्रह (`colecao`,
स्वामी और वृक्ष की जड़ के साथ), सदस्य (`colecao_usuario`), किसी स्वामी के सब कुछ की पहुँच (`acesso_dono`), निमंत्रण, अनुरोध,
टोकन, Web Push के उपकरण, गंतव्य (`destino` और हर प्रकार की एक तालिका) और कैलेंडर (`agenda` और हर प्रकार की एक तालिका)।
वृक्ष (`no`) की जड़ में स्वामी है और समूह ऊपर वाले की ओर इंगित करते हैं; उसकी परिभाषाएँ (फ़ील्ड, प्रकार, सूत्र, अलर्ट,
श्रेणियाँ) वृक्ष के किसी समूह की ओर इंगित करती हैं; जो संग्रह का है (घड़ियाँ, मोड, योजना, इवेंट, सेटिंग्स, एक्सेसरी) वह
संग्रह की ओर इंगित करता है। वैकल्पिक संबंध एक पंक्ति वाली तालिकाएँ बन गए (एक्सेसरी की घड़ी, ब्लॉक की तय घड़ी, योजना के
किसी दिन का ब्लॉक)। डेटाबेस में सीधे हटाने से कुछ भी अधूरा नहीं रहता:

| जुड़ाव | ऊपर वाले को हटाने पर | कहाँ |
|---|---|---|
| अनिवार्य (ऊपर वाले के बिना पंक्ति मौजूद नहीं) | **साथ में हटता है** (`ON DELETE CASCADE`) | एक खाता अपने संग्रह और वृक्ष ले जाता है; एक संग्रह घड़ियाँ, मोड, योजना, गंतव्य और कैलेंडर ले जाता है; एक वृक्ष उसे उपयोग करने वाले संग्रह ले जाता है; एक समूह अपने भीतर के समूह, घड़ियाँ, फ़ील्ड, प्रविष्टि के प्रकार, सूत्र और अपने अलर्ट ले जाता है; एक घड़ी प्रविष्टियाँ, मान, फ़ोटो, दस्तावेज़, योजना और अपने मानदंड ले जाती है; एक प्रविष्टि खपत का माप ले जाती है; और इसी तरह |
| वैकल्पिक (कॉलम ख़ाली स्वीकार करता है) | **जुड़ाव टूट जाता है** (`ON DELETE SET NULL`) | कस्टम इवेंट की घड़ी, मोड ब्लॉक का समूह और तय घड़ी, योजना के किसी दिन का ब्लॉक, अलर्ट हल करने वाला प्रविष्टि प्रकार, एक्सेसरी की घड़ी (एक्सेसरी अलग हो जाती है) |

स्क्रीन से (**समूह**, हटाएँ), समूह हटाने पर पहले उसका सब कुछ (भीतर के समूह, घड़ियाँ, फ़ील्ड, प्रकार, सूत्र और अलर्ट) ऊपर वाले समूह
में चला जाता है; कैस्केड उसके लिए है जो सीधे डेटाबेस में हटाता है।

सूचियाँ तालिकाएँ बन गईं: मोड के ब्लॉक के दिन (`modo_bloco_dia`) और साप्ताहिक इवेंट के दिन (`evento_dia`), दस्तावेज़ों की कोई
श्रेणी जो फ़ाइल प्रकार स्वीकार करती है (`documento_categoria_aceita`), सूची वाले फ़ील्ड के विकल्प (`campo_opcao`) और, हर
गंतव्य और हर कैलेंडर के लिए, वे क्या पाते हैं (`destino_aviso`, `destino_evento`, `agenda_aviso`, `agenda_evento`)। उपयोग में
मोड, मोड का `ativo` कॉलम है, और खपत का माप उस प्रविष्टि से घड़ी तक पहुँचता है जिसने उसे बंद किया। `config` तालिका केवल हर
संग्रह के अलग-अलग मान रखती है, और `sistema`, पूरे सिस्टम के।

तीन संदर्भ जान-बूझकर **नाम से** रहते हैं: सूत्र और मानदंड फ़ील्डों और सूत्रों को पहचानकर्ता से बताते हैं (यह वही पाठ है जो
आप लिखते हैं, जैसे `HORAS("pulso"; 30)`), और `destino_aviso` और `agenda_aviso` अलर्ट को पहचानकर्ता से बताते हैं, क्योंकि एक
अलर्ट के कई संस्करण हो सकते हैं (हर समूह का एक) और चुनाव सभी पर लागू होता है। जब किसी अलर्ट का अंतिम संस्करण हटाया जाता
है, सिस्टम उसकी पंक्तियाँ भी साथ में हटा देता है।

**डेटाबेस जो रखता है उसे सत्यापित करता है** (माइग्रेशन v17 से), मानक SQL की सुविधाओं से, सिर्फ़ सिस्टम नहीं: ग़लत लेखन कहीं से भी
आए (स्क्रीन, API, कोई सीधे डेटाबेस बदले) अस्वीकार होता है। API `409` लौटाती है, विवरण में टूटे नियम के नाम के साथ।

| सुविधा | क्या गारंटी देती है |
|---|---|
| `NOT NULL` | जो ज़रूरी है वह भरा है (उदाहरण के लिए हर प्रविष्टि और योजना के हर दिन का मूल) |
| `CHECK` | हर मान मान्य सीमा में। 54 नियम हैं, हर एक नाम के साथ (`ck_<तालिका>_<क्या>`) |
| बंद सूचियाँ (`ENUM`, जो बाक़ी डेटाबेस में `CHECK ... IN` बन जाता है) | कॉलम जो मान स्वीकार करता है: प्रविष्टि प्रकार का फ़ॉर्मेट, इवेंट की पुनरावृत्ति, प्रविष्टि का मूल (`manual`, `rodizio`, `importado`) और योजना के दिन का (`sorteio`, `manual`)... |
| `UNIQUE` | जो दोहराया नहीं जा सकता: फ़ील्ड, प्रकार, श्रेणी का पहचानकर्ता; दस्तावेज़ की फ़ाइल; **उपयोग में ज़्यादा से ज़्यादा एक मोड** |
| `FOREIGN KEY` | जुड़ाव (ऊपर) |

`CHECK` नियम, विषय के अनुसार:

| विषय | डेटाबेस अस्वीकार करता है |
|---|---|
| टेक्स्ट | नाम, पहचानकर्ता, शीर्षक, अलर्ट का टेक्स्ट, सूत्र और सेटिंग्स की कुंजी ख़ाली या सिर्फ़ स्पेस |
| हाँ/नहीं निशान | 0 और 1 के अलावा कोई भी मान (उपलब्ध, सक्रिय, एकल, खपत मापता है, इस्तेमाल, भेजता है, डेटाबेस में कॉपी, सिर्फ़ इच्छित पर...) |
| घड़ियाँ | सूची से बाहर की संग्रह-स्थिति (`colecao`, `desejado`, `emprestado`, `vendido`, `arquivado`); संग्रह से बाहर उपलब्ध घड़ी |
| प्रविष्टि प्रकार | सत्र फ़ॉर्मेट के बाहर एकल सत्र; मान वाले फ़ॉर्मेट के बाहर "खपत मापता है"; सत्र फ़ॉर्मेट के बाहर "बंद होता है" |
| प्रविष्टियाँ और माप | शुरुआत से पहले अंत; मापी गई खपत (दर) 0 से 100% के बाहर; ऋणात्मक घंटे |
| अलर्ट | अग्रिम 0 से 3650 दिन के बाहर; शेड्यूल में सिमुलेट घंटे 0 से 48 के बाहर |
| मानदंड | भार 0 से 100% के बाहर; अंक 0 से 100 के बाहर; एक ही समय किसी समूह **और** किसी घड़ी का मानदंड सेट; ऐसी सीमा जो न संख्या वाली ("से" के साथ) न श्रेणी वाली; ऋणात्मक "से"; "तक" जो "से" से आगे न जाए |
| रोटेशन मोड | स्मार्ट शेड्यूल 7 से 730 दिन के बाहर; सप्ताह का दिन 1 से 7 के बाहर |
| कस्टम इवेंट | जो पुनरावृत्ति इस्तेमाल नहीं करती (मासिक के बाहर महीने का दिन, "हर N दिन" के बाहर अंतराल, "एक बार" और "हर N दिन" के बाहर तारीख़) और जो वह इस्तेमाल करती है उसकी कमी; महीने का दिन 1 से 31 के बाहर; अंतराल 1 से 3650 दिन के बाहर; सप्ताह का दिन 1 से 7 के बाहर |
| एक्सेसरी | ख़ाली नाम; सूची से बाहर का प्रकार (`pulseira`, `caixa`, `estojo`, `ferramenta`, `carregador`, `peca`, `outro`) |
| दस्तावेज़ | शून्य आकार; SHA-256 जिसमें 64 अक्षर न हों; डेटाबेस में कॉपी का हिस्सा -1 से नीचे (-1 थंबनेल है) |
| घड़ी की फ़ोटो | ऐसा प्रकार जो छवि नहीं |
| cron के रन | शुरुआत से पहले अंत; ऋणात्मक अवधि |

**उपयोग में ज़्यादा से ज़्यादा एक मोड:** मोड का `ativo` निशान उपयोग वाले में 1 और बाक़ी में ख़ाली (`NULL`) है, और कॉलम पर यूनिक की
है। मानक SQL यूनिक की में ख़ाली दोहराने देता है, पर 1 नहीं: दो मोड उपयोग में हों तो डेटाबेस अस्वीकार करता है।

दो नियम MySQL 8 की वजह से बाहर रहे, जो `id` कॉलम (ऑटो-इंक्रीमेंट) पर और `ON DELETE SET NULL` वाले फ़ॉरेन की कॉलम पर `CHECK`
स्वीकार नहीं करता: "समूह ख़ुद का माता-पिता नहीं" (सिस्टम जाँचता है, और बड़े चक्र भी जाँचता है, जिन तक कोई `CHECK` नहीं पहुँचता) और
वैकल्पिक जुड़ावों वाले।

### फ़ाइलें {#arquivos}

| फ़ाइल | यह क्या है |
|---|---|
| [`api.php`](api.php) | API, हर पढ़ने और लिखने का दरवाज़ा (प्रमाणीकरण, फ़ॉर्मेट, संसाधनों का क्रम, फ़िल्टर, जवाब), हेडर में पूरे दस्तावेज़ीकरण के साथ |
| [`api/`](api/) | API के बड़े संसाधन, हर एक की एक फ़ाइल (`hoje.php`, `ficha.php`, `configuracao.php`, `desejos.php`, `estatisticas.php`, `hora_certa.php`, `passaporte.php`, `galeria.php`, `ajuda.php`...), साथ में उसके फ़ंक्शन (`funcoes.php`) और लेखन (`escrita.php`): ये `api.php` के भीतर चलते हैं, और सीधे खोलने पर कुछ नहीं करते (`.htaccess` और `nginx-relogios.conf` फ़ोल्डर को रोकते हैं) |
| [`lib.php`](lib.php) | केंद्र: लॉगिन, वृक्ष, सूत्रों का इंजन, अलर्ट, मानदंड, रोटेशन, शेड्यूल, दस्तावेज़ |
| [`colecoes.php`](colecoes.php) | केंद्र में संग्रह: अनुरोध का दायरा, `modelo.json`, एन्क्रिप्टेड रहस्य, `sistema` तालिका, माइग्रेशन v25 और हटाने का प्रभाव |
| [`alertas.php`](alertas.php) | गंतव्य (webhook, ई-मेल, Web Push) और कैलेंडर (Google, CalDAV, Outlook, ICS) |
| [`contas.php`](contas.php) | खाते: खुला पंजीकरण, मेरा खाता, संग्रह और वृक्ष, निमंत्रण, अनुरोध, टोकन और master का पैनल |
| [`banco.php`](banco.php) | डेटाबेस: MySQL, Postgres, SQLite या Firebird से कनेक्शन, और एक से दूसरे में SQL का अनुवाद |
| [`operacoes.php`](operacoes.php) | हर लेखन के नियम: जाँच और संदेश |
| [`cron.php`](cron.php) | योजना, दिन का सत्र, सुबह और शाम के दौर, इवेंट, कैलेंडर और दस्तावेज़ों की देखभाल |
| `index.php`, `plano.php`, `desejos.php`, `estatisticas.php`, `hora.php`, `acessorios.php`, `galeria.php`, `passaporte.php`, `ficha.php`, `documentos.php`, `historico.php`, `avisos.php`, `modos.php`, `configuracao.php`, `destinos.php`, `agendas.php`, `criterios.php`, `grupos.php`, `cadastros.php`, `execucoes.php`, `conta.php`, `aprovacoes.php`, `usuarios.php`, `cadastro.php` | पृष्ठ (केवल ढाँचा; `cadastro.php` सार्वजनिक है) |
| `relojinho.js` | बार और फ़ेविकॉन की छोटी घड़ी: canvas में बनी सही समय वाली 24 तरह की घड़ियाँ, हर घंटे एक (क्रम से, चुनी हुई या बिना दोहराव) |
| `sw.js`, `offline.html`, `icone-*.png` | फ़ोन पर ऐप: *service worker* (`recurso=sw` से, संस्करण आगे लगाकर: स्क्रीनों का कैश, बिना-कनेक्शन पेज और Web Push), **कनेक्शन नहीं है** पेज और आइकन (192, 512 और 180 px) |
| `ajuda.php` | सहायता पेज: सिर्फ़ ढाँचा; `ajuda.js` API से टेक्स्ट माँगता है (`recurso=manual`: README के उपयोगकर्ताओं वाले हिस्से, `lib.php` में पहले से HTML में बदले हुए) |
| [`pagina.php`](pagina.php) | मेनू वाला ऊपरी हिस्सा और पेजों का अंत ; `topo()` कुछ भी भेजने से पहले लॉगिन जाँचता है (`pagina_login()`) |
| `api.js`, `hoje.js`, `painel.js`, `tabela.js`, `foto.js`, ... | स्क्रीनें, ब्राउज़र में API से बनाई गईं |
| [`estilo.css`](estilo.css) | रूप |
| [`textos/`](textos/) | भाषाएँ: `funcoes.php` (`t()` फ़ंक्शन, भाषाओं की सूची, अनुरोध की भाषा, हर भाषा की तारीख़ें और संख्याएँ) और हर भाषा की एक फ़ाइल (`en.php`, `zh-CN.php`, `hi.php`, `es.php`, `fr.php`: पुर्तगाली टेक्स्ट => अनुवाद, हिस्सों में); सीधे खोलने पर कुछ नहीं करतीं |
| [`schema.sql`](schema.sql) | डेटाबेस की संरचना (केवल संरचना: बिना डेटा) |
| [`modelo.json`](modelo.json) | नए संग्रह का प्रारंभिक सेट (समूह, फ़ील्ड, प्रकार, सूत्र, अलर्ट, मानदंड, श्रेणियाँ, मोड, संदेश), बिना तय कोड के |
| `migracao_v2.sql` … `migracao_v24.sql` | माइग्रेशन, सेटिंग्स पेज से लागू किए जाते हैं |
| [`config.exemplo.php`](config.exemplo.php) | `config.php` का नमूना |
| [`instalar.php`](instalar.php) | `config.php` के डेटाबेस में `schema.sql` इंस्टॉल करता है, कोई भी हो (वही जो `recurso=instalacao`) |
| [`criar_usuario.php`](criar_usuario.php) | कमांड लाइन से उपयोगकर्ता बनाता है या पासवर्ड बदलता है (वही जो `recurso=usuarios`, `acao=salvar`) |
| [`importar.php`](importar.php) | पिछले सिस्टम का डेटा आयात करता है (वही जो `recurso=importacao`) |
| [`reconstrucao.php`](reconstrucao.php) | सिस्टम को शून्य से दोबारा लिखने की गाइड (API का `recurso=reconstrucao`) |
| [`.htaccess`](.htaccess) | Apache में अंदरूनी फ़ाइलों की सुरक्षा (और FastCGI वाले PHP के साथ साइट का लॉगिन) |
| [`nginx-relogios.conf`](nginx-relogios.conf) | nginx का ब्लॉक जो अंदरूनी फ़ाइलों की रक्षा करता है |
| [`testes/`](testes/) | पैरिटी टेस्ट: हर डेटाबेस में API से एक ही स्क्रिप्ट, और जवाबों की तुलना करने वाला; फ़ोन से कंप्यूटर तक सभी स्क्रीनों के लेआउट की जाँच, और रिकॉर्ड व आज के पैनल के बीच दर्पण की (खंडों और बॉक्सों का एक ही क्रम) (`layout.cjs`; `LINGUA=en` किसी भाषा में जाँचता है); भाषाओं के शब्दकोशों की जाँच (`textos.php`); हर भाषा का README बनाना (`readme.php`); और आर्किटेक्चर की जाँच (`arquitetura.php`: वे नियम जो कोड को मानने हैं) |
| [`docs/telas/`](docs/telas/) | इस README के स्क्रीनशॉट |
| `README.md`, `README.en.md`, `README.zh-CN.md`, `README.hi.md`, `README.es.md`, `README.fr.md` | यह README: मूल, पुर्तगाली में, और बाक़ी भाषाएँ। इन्हें हाथ से नहीं बदला जाता: [`testes/readme.php`](testes/readme.php) हर एक को अनुवादित मैनुअल (`textos/manual/<भाषा>.md`, वे हिस्से जो सहायता दिखाती है) और अनुवादित बाक़ी हिस्से (`textos/readme/<भाषा>.md`: शुरुआत, आज़माएँ, API, इंस्टॉलेशन, भीतर से यह कैसा है, योगदान कैसे करें और लाइसेंस) को जोड़कर बनाता है |
| [`PORTE.md`](PORTE.md) | पिछले संस्करण से लाने का रिकॉर्ड |

---

## योगदान कैसे करें {#como-contribuir}

सुधारों का स्वागत है, और रास्ता हमेशा यही रिपॉज़िटरी है:

1. बड़े बदलाव के लिए **पहले एक issue खोलें** और बताएँ कि आप क्या करना चाहते हैं: इससे वह काम बचता है जो शामिल नहीं होगा।
2. GitHub पर fork करें, एक branch बनाएँ और बदलाव करें। fork सिर्फ़ प्रस्ताव तैयार करने के लिए है (देखें [लाइसेंस](#licença))।
3. **कोड के तरीक़े का पालन करें:** शुद्ध PHP और JavaScript, बिना निर्भरताओं के, टिप्पणियाँ और संदेश पुर्तगाली में (अनुवाद
   `textos/` में जाते हैं)। घड़ियों के बारे में जो ज्ञान है वह प्रारंभिक सेट (`modelo.json`) में जाता है, कोड में नहीं।
4. डेटाबेस बदला? एक `migracao_vN.sql` बनाएँ और उसे `lib.php` के `$MIGRACOES` में दर्ज करें: संरचना MySQL की बोली में, डेटा चारों
   डेटाबेस के साझा SQL में (देखें [डेटाबेस के अपडेट](#atualizações-do-banco))।
5. API बदली? सहायता के शब्दकोश में **हर नया फ़ील्ड समझाएँ**: `api/ajuda.php` में `recurso=ajuda` का `"campos"` (संसाधन का हिस्सा,
   फ़ील्ड के पाथ को कुंजी बनाकर)। यही एकमात्र स्रोत है: `api.php` के हेडर का शब्दकोश इसी से `php testes/dicionario.php --gravar`
   से बनता है (हेडर में हाथ से न लिखें)।
6. भेजने से पहले चारों डेटाबेस में **पैरिटी टेस्ट चलाएँ** ([`testes/`](testes/)) (GitHub भी `main` पर हर push पर चलाता है: पैरिटी
   टेस्ट, दस्तावेज़ीकरण और लेआउट, [`.github/workflows/testes.yml`](.github/workflows/testes.yml) में; नतीजा **Actions** टैब में और
   हर commit के बगल में ✓ / ✗ में दिखता है): `testes/paridade.sh`, डेटाबेस चालू रहते हुए, `Tudo igual.` पर ख़त्म होना चाहिए। यह
   दस्तावेज़ीकरण भी जाँचता है: हेडर वही है जो सहायता बनाती है, और जवाबों का हर फ़ील्ड समझाया गया है। यह आर्किटेक्चर भी जाँचता है ([`testes/arquitetura.php`](testes/arquitetura.php), जिसे GitHub बिना
   डेटाबेस के एक तेज़ क़दम में भी चलाता है): पेज सिर्फ़ ढाँचा बनाते हैं, स्क्रीनों का नेटवर्क सिर्फ़ API है, डेटाबेस को सिर्फ़ कोर,
   API और cron छूते हैं, अंदरूनी फ़ाइलें वेब से बंद हैं, हर क्रिया और `config.php` का हर स्थिरांक दस्तावेज़ में है, और फ़ाइल
   की शुरुआत में बताए बाक़ी नियम। और अगर दौर के दौरान PHP कोई भी चेतावनी (Warning, Notice, Deprecated) दे तो यह विफल होता है।
   स्क्रीनें बदलीं? लेआउट की
   जाँच भी चलाएँ (`testes/paridade.sh --layout sqlite`): `0 problemas`।
7. **हर नया टेक्स्ट `t()` से गुज़रता है** (`api.js` और `textos/funcoes.php` में यह फ़ंक्शन है), पुर्तगाली को कुंजी बनाकर और
   पैरामीटर कोष्ठकों में: `t("Excluir {1}?", {1: nome})`, कभी `"Excluir " + nome + "?"` नहीं। स्क्रीन या API में सीधे लिखा कोई टेक्स्ट नहीं: GitHub इसे दो तरह से जाँचता है। `testes/arquitetura.php` का नियम 11 `t()` के बाहर स्क्रीन पर जाने वाले उद्धरण-चिह्नों वाले टेक्स्ट को अस्वीकार करता है (जो जानबूझकर अनूदित नहीं होता, जैसे ब्रांड, वह `fixo("...")` में जाता है), और `LINGUA=zh-CN testes/paridade.sh --textos sqlite` पूरे सिस्टम को चीनी में करके हर स्क्रीन खोलता है और लैटिन अक्षरों वाला हर वह शब्द पकड़ता है जो न उपयोगकर्ता का डेटा है न शब्दकोश से आता है। फिर `textos/` की पाँचों फ़ाइलों
   में उसका अनुवाद करें और `php testes/textos.php` चलाएँ (पैरिटी टेस्ट भी चलाता है): यह बिना किसी कमी के ख़त्म होना चाहिए। README
   बदला? उसके अनुवादों पर उस संस्करण का निशान है जिसका वे अनुवाद करते हैं (`textos/manual/<भाषा>.md` और
   `textos/readme/<भाषा>.md` की पहली पंक्ति), और `php testes/textos.php` पीछे रह गए अनुवाद को बताता है: बदले हिस्से का अनुवाद करें,
   नया निशान लगाएँ (`php testes/textos.php --manual` और `php testes/readme.php --original` उसके साथ मूल देते हैं) और
   `php testes/readme.php` चलाएँ, जो `README.<भाषा>.md` फ़ाइलें फिर से बनाता है।
8. pull request खोलें, बताते हुए कि उपयोगकर्ता के लिए क्या बदलता है और आपने कैसे जाँचा।

---

## लाइसेंस {#licença}

Relojoeiro **सोर्स-उपलब्ध** है, मुक्त सॉफ़्टवेयर नहीं: कोड दिखता है, पर उसका मालिक और उपयोग के नियम हैं। संक्षेप में (मान्य वही है
जो [`LICENSE`](LICENSE) के पुर्तगाली टेक्स्ट में है):

| ✅ कर सकते हैं | ❌ नहीं कर सकते |
|---|---|
| अपने, अपने परिवार या अपनी कंपनी के लिए इंस्टॉल और इस्तेमाल करना | इस कोड पर आधारित नया प्रोजेक्ट बनाना, पूरा या आंशिक, न किसी दूसरी भाषा में दोबारा लिखकर |
| अपने इंस्टॉलेशन का कोड बदलना | कोड को, मूल या बदला हुआ, इस रिपॉज़िटरी के बाहर बाँटना |
| यहाँ pull request से सुधार प्रस्तावित करना | सिस्टम को दूसरों को सेवा के रूप में देना |
| इन प्रस्तावों को तैयार करने के लिए GitHub पर fork करना | fork को अलग प्रोजेक्ट के रूप में रखना |

किसी भी दूसरे उपयोग के लिए GitHub से अनुमति माँगें ([Challado](https://github.com/Challado))।

> 💡 **योगदान दें, नक़ल न करें।** Relojoeiro का योगदानकर्ता होना fork करने से कहीं बेहतर है: आपका सुधार सबके लिए जाता है, इतिहास में
> आपके नाम के साथ, और आपको कोई जोखिम नहीं। वहीं जो fork अपना प्रोजेक्ट बन जाए वह लाइसेंस का उल्लंघन करता है, और इस कोड के
> अधिकारों की रक्षा की जाती है: प्लेटफ़ॉर्म पर हटाने का अनुरोध, हर्जाने के साथ मुक़दमा और आपराधिक कार्रवाई तक (ब्राज़ील का क़ानून
> 9.609/98 सॉफ़्टवेयर के कॉपीराइट उल्लंघन को अपराध मानता है)। ख़ुद को सही मानने वाले को भी अपना बचाव करना पड़ सकता है, और इसमें
> समय और वकील लगते हैं। **शक हो तो पहले पूछें:** जवाब मुफ़्त है, बाद की बहस नहीं।

---

<p align="center"><sub>उनके लिए बनाया गया जिनके पास कलाइयों से ज़्यादा घड़ियाँ हैं। ⌚</sub></p>
