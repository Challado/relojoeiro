// O reloginho (um easter egg): doze relógios diferentes, desenhados na hora, cada um mostrando a hora certa de agora. Um
// fica na barra do menu, no canto esquerdo (os segundos andando; um clique abre a Hora certa; parando o mouse, o modelo e
// a hora), e o outro é o favicon, na aba do navegador (desenhado a cada minuto). A hora cheia escolhe o modelo: às 7 e às
// 19 é o Aviador, às 10 e às 22 o Solar... Tudo em canvas, sem imagem nenhuma e sem a API: a hora é a do aparelho.
var RELOJINHO_MODELOS = [
  {nome: t("Clássico"), caixa: "redonda", metal: "#d4af37", mostrador: "#fbf7ee", indices: "barras", cor_indices: "#2b2b2b", ponteiros: "#2b2b2b", estilo: "dauphine", pulseira: "couro", cor_pulseira: "#6b3f1f"},
  {nome: t("Mergulhador"), caixa: "redonda", metal: "#c9ced4", mostrador: "#101418", indices: "pontos", cor_indices: "#e9f2d6", ponteiros: "#e9f2d6", estilo: "baston", bisel: "mergulho", cor_bisel: "#15243a", pulseira: "aco", cor_pulseira: "#c9ced4"},
  {nome: t("Aviador"), caixa: "redonda", metal: "#2a2f36", mostrador: "#15181c", indices: "numeros", cor_indices: "#f1efe6", ponteiros: "#f1efe6", estilo: "espada", triangulo: true, pulseira: "couro", cor_pulseira: "#5a3a22"},
  {nome: t("Militar"), caixa: "redonda", metal: "#4a4f45", mostrador: "#3b4a2f", indices: "numeros", cor_indices: "#e6e2c8", ponteiros: "#e6e2c8", estilo: "baston", pulseira: "nato", cor_pulseira: "#556b2f", cor_pulseira2: "#8b7355"},
  {nome: t("Smartwatch"), caixa: "quadrada", metal: "#1c1f24", mostrador: "#000000", indices: "pontos", cor_indices: "#5de0a8", ponteiros: "#ffffff", estilo: "baston", anel: "#5de0a8", pulseira: "silicone", cor_pulseira: "#2d3a4a"},
  {nome: t("Tanque"), caixa: "retangular", metal: "#d4af37", mostrador: "#f7f3ea", indices: "barras", cor_indices: "#1c2530", ponteiros: "#1f4f8f", estilo: "espada", pulseira: "couro", cor_pulseira: "#1b1b1b"},
  {nome: t("Esportivo azul"), caixa: "redonda", metal: "#c9ced4", mostrador: "#1f4f8f", indices: "barras", cor_indices: "#f2f5f8", ponteiros: "#f2f5f8", estilo: "baston", brilho: true, pulseira: "aco", cor_pulseira: "#c9ced4"},
  {nome: t("Cronógrafo"), caixa: "redonda", metal: "#c9ced4", mostrador: "#f3f1ea", indices: "barras", cor_indices: "#1c2530", ponteiros: "#1c2530", estilo: "baston", bisel: "taquimetro", cor_bisel: "#1c1c1c", subdiais: true, pulseira: "couro", cor_pulseira: "#2b2b2b"},
  {nome: t("Esqueleto"), caixa: "redonda", metal: "#b76e79", mostrador: "#2a2326", indices: "pontos", cor_indices: "#e8c9a0", ponteiros: "#e8c9a0", estilo: "dauphine", esqueleto: true, pulseira: "couro", cor_pulseira: "#4a2c2a"},
  {nome: t("Solar"), caixa: "redonda", metal: "#c9ced4", mostrador: "#2a2a2a", indices: "barras", cor_indices: "#f4f4f4", ponteiros: "#f4f4f4", estilo: "baston", solar: "#e08a1e", pulseira: "borracha", cor_pulseira: "#1c1c1c"},
  {nome: t("Vintage"), caixa: "almofada", metal: "#b8a06a", mostrador: "#e9dcbf", indices: "numeros", cor_indices: "#5a4a2e", ponteiros: "#5a4a2e", estilo: "dauphine", pulseira: "couro", cor_pulseira: "#8a5a2b"},
  {nome: t("GMT"), caixa: "redonda", metal: "#c9ced4", mostrador: "#0e0e10", indices: "pontos", cor_indices: "#f0f0f0", ponteiros: "#f0f0f0", estilo: "baston", bisel: "gmt", cor_bisel: "#8b1e2d", cor_bisel2: "#1f3b6e", gmt: "#d9342b", pulseira: "aco", cor_pulseira: "#c9ced4"},
];

// Desenha um modelo num contexto 2D, num quadrado de lado tam, na hora dada (com ou sem o ponteiro dos segundos)
function relojinhoDesenhar(ctx, tam, m, data, segundos) {
  var c = tam / 2;
  var r = tam * 0.34;
  ctx.clearRect(0, 0, tam, tam);
  // a pulseira: duas tiras atrás da caixa
  var lp = tam * 0.3;
  ctx.fillStyle = m.cor_pulseira;
  ctx.fillRect(c - lp / 2, 0, lp, tam);
  if (m.pulseira === "nato") {
    ctx.fillStyle = m.cor_pulseira2;
    ctx.fillRect(c - lp / 6, 0, lp / 3, tam);
  } else if (m.pulseira === "aco") {
    ctx.strokeStyle = "rgba(0, 0, 0, .25)";
    ctx.lineWidth = Math.max(1, tam * 0.012);
    for (var y = tam * 0.06; y < tam; y += tam * 0.12) {
      ctx.beginPath();
      ctx.moveTo(c - lp / 2, y);
      ctx.lineTo(c + lp / 2, y);
      ctx.stroke();
    }
  } else if (m.pulseira === "couro") {
    ctx.strokeStyle = "rgba(255, 255, 255, .25)";
    ctx.lineWidth = Math.max(1, tam * 0.012);
    ctx.beginPath();
    ctx.moveTo(c - lp / 2 + tam * 0.03, 0);
    ctx.lineTo(c - lp / 2 + tam * 0.03, tam);
    ctx.moveTo(c + lp / 2 - tam * 0.03, 0);
    ctx.lineTo(c + lp / 2 - tam * 0.03, tam);
    ctx.stroke();
  }
  // a coroa
  ctx.fillStyle = m.metal;
  ctx.fillRect(c + r + tam * 0.05, c - tam * 0.035, tam * 0.06, tam * 0.07);
  // a caixa e o mostrador
  var forma = function (raio, aro) {
    ctx.beginPath();
    if (m.caixa === "redonda") {
      ctx.arc(c, c, raio, 0, Math.PI * 2);
    } else if (m.caixa === "quadrada") {
      relojinhoRetangulo(ctx, c - raio, c - raio, raio * 2, raio * 2, raio * 0.3);
    } else if (m.caixa === "retangular") {
      relojinhoRetangulo(ctx, c - raio * 0.8, c - raio * 1.05, raio * 1.6, raio * 2.1, raio * 0.12);
    } else {
      relojinhoRetangulo(ctx, c - raio, c - raio, raio * 2, raio * 2, raio * 0.55);
    }
    ctx.closePath();
  };
  forma(r + tam * 0.06);
  ctx.fillStyle = m.metal;
  ctx.fill();
  if (m.bisel) {
    forma(r + tam * 0.055);
    ctx.fillStyle = m.cor_bisel;
    ctx.fill();
    if (m.bisel === "gmt") {
      ctx.beginPath();
      ctx.moveTo(c, c);
      ctx.arc(c, c, r + tam * 0.055, Math.PI * 0.5, Math.PI * 1.5);
      ctx.closePath();
      ctx.fillStyle = m.cor_bisel2;
      ctx.fill();
    }
    // as marcas do bisel
    ctx.strokeStyle = m.bisel === "taquimetro" ? "#e6e6e6" : "#f4f4f4";
    ctx.lineWidth = Math.max(1, tam * 0.012);
    for (var b = 0; b < 12; b++) {
      var ab = b * Math.PI / 6;
      ctx.beginPath();
      ctx.moveTo(c + Math.sin(ab) * (r + tam * 0.015), c - Math.cos(ab) * (r + tam * 0.015));
      ctx.lineTo(c + Math.sin(ab) * (r + tam * 0.05), c - Math.cos(ab) * (r + tam * 0.05));
      ctx.stroke();
    }
  }
  forma(r);
  ctx.fillStyle = m.mostrador;
  ctx.fill();
  if (m.brilho) {
    var g = ctx.createLinearGradient(c - r, c - r, c + r, c + r);
    g.addColorStop(0, "rgba(255, 255, 255, .28)");
    g.addColorStop(0.5, "rgba(255, 255, 255, 0)");
    g.addColorStop(1, "rgba(0, 0, 0, .25)");
    ctx.fillStyle = g;
    ctx.fill();
  }
  if (m.anel) {
    forma(r - tam * 0.02);
    ctx.strokeStyle = m.anel;
    ctx.lineWidth = Math.max(1, tam * 0.025);
    ctx.stroke();
  }
  if (m.solar) {
    ctx.strokeStyle = m.solar;
    ctx.lineWidth = Math.max(1, tam * 0.01);
    for (var s = 0; s < 24; s++) {
      var as = s * Math.PI / 12;
      ctx.beginPath();
      ctx.moveTo(c + Math.sin(as) * r * 0.25, c - Math.cos(as) * r * 0.25);
      ctx.lineTo(c + Math.sin(as) * r * 0.62, c - Math.cos(as) * r * 0.62);
      ctx.stroke();
    }
  }
  if (m.esqueleto) {
    ctx.strokeStyle = "rgba(232, 201, 160, .55)";
    ctx.lineWidth = Math.max(1, tam * 0.012);
    [[-0.3, 0.25, 0.28], [0.3, -0.2, 0.2], [0.05, 0.4, 0.14]].forEach(function (e) {
      ctx.beginPath();
      ctx.arc(c + e[0] * r, c + e[1] * r, e[2] * r, 0, Math.PI * 2);
      ctx.stroke();
    });
  }
  if (m.subdiais) {
    ctx.fillStyle = "#d9d5cb";
    [[-0.45, 0], [0.45, 0], [0, 0.45]].forEach(function (e) {
      ctx.beginPath();
      ctx.arc(c + e[0] * r, c + e[1] * r, r * 0.17, 0, Math.PI * 2);
      ctx.fill();
    });
  }
  // os índices
  ctx.fillStyle = m.cor_indices;
  ctx.strokeStyle = m.cor_indices;
  var ri = m.caixa === "retangular" ? r * 0.72 : r;
  for (var h = 0; h < 12; h++) {
    var a = h * Math.PI / 6;
    var grande = h % 3 === 0;
    var x1 = c + Math.sin(a) * ri * 0.92;
    var y1 = c - Math.cos(a) * ri * (m.caixa === "retangular" ? 1.25 : 0.92);
    if (m.triangulo && h === 0) {
      ctx.beginPath();
      ctx.moveTo(c - r * 0.1, c - r * 0.9);
      ctx.lineTo(c + r * 0.1, c - r * 0.9);
      ctx.lineTo(c, c - r * 0.72);
      ctx.closePath();
      ctx.fill();
    } else if (m.indices === "pontos") {
      ctx.beginPath();
      ctx.arc(x1, y1, r * (grande ? 0.08 : 0.05), 0, Math.PI * 2);
      ctx.fill();
    } else if (m.indices === "numeros" && grande) {
      ctx.font = "bold " + Math.round(r * 0.42) + "px Arial, sans-serif";
      ctx.textAlign = "center";
      ctx.textBaseline = "middle";
      ctx.fillText(String(h === 0 ? 12 : h), c + Math.sin(a) * ri * 0.76, c - Math.cos(a) * ri * 0.76 + r * 0.02);
    } else {
      ctx.lineWidth = Math.max(1, r * (grande ? 0.09 : 0.05));
      ctx.beginPath();
      ctx.moveTo(c + Math.sin(a) * ri * (grande ? 0.74 : 0.82), c - Math.cos(a) * ri * (m.caixa === "retangular" ? (grande ? 1.0 : 1.12) : (grande ? 0.74 : 0.82)));
      ctx.lineTo(x1, y1);
      ctx.stroke();
    }
  }
  // os ponteiros
  var hh = data.getHours() % 12 + data.getMinutes() / 60;
  var mm = data.getMinutes() + data.getSeconds() / 60;
  var ponteiro = function (ang, comp, larg, cor) {
    ctx.save();
    ctx.translate(c, c);
    ctx.rotate(ang);
    ctx.fillStyle = cor;
    ctx.beginPath();
    if (m.estilo === "dauphine") {
      ctx.moveTo(0, r * 0.1);
      ctx.lineTo(-larg, 0);
      ctx.lineTo(0, -comp);
      ctx.lineTo(larg, 0);
    } else if (m.estilo === "espada") {
      ctx.moveTo(-larg * 0.6, r * 0.1);
      ctx.lineTo(-larg, -comp * 0.7);
      ctx.lineTo(0, -comp);
      ctx.lineTo(larg, -comp * 0.7);
      ctx.lineTo(larg * 0.6, r * 0.1);
    } else {
      ctx.rect(-larg / 2, -comp, larg, comp + r * 0.1);
    }
    ctx.closePath();
    ctx.fill();
    ctx.restore();
  };
  ponteiro(hh * Math.PI / 6, r * 0.55, Math.max(1.5, r * 0.09), m.ponteiros);
  ponteiro(mm * Math.PI / 30, r * 0.82, Math.max(1.2, r * 0.065), m.ponteiros);
  if (m.gmt) {
    ponteiro((data.getHours() + data.getMinutes() / 60) * Math.PI / 12, r * 0.72, Math.max(1, r * 0.04), m.gmt);
  }
  if (segundos) {
    ctx.save();
    ctx.translate(c, c);
    ctx.rotate(data.getSeconds() * Math.PI / 30);
    ctx.strokeStyle = m.solar || m.gmt || (m.anel ? m.anel : "#c8402f");
    ctx.lineWidth = Math.max(1, r * 0.03);
    ctx.beginPath();
    ctx.moveTo(0, r * 0.15);
    ctx.lineTo(0, -r * 0.9);
    ctx.stroke();
    ctx.restore();
  }
  ctx.fillStyle = m.ponteiros;
  ctx.beginPath();
  ctx.arc(c, c, Math.max(1.5, r * 0.08), 0, Math.PI * 2);
  ctx.fill();
}

function relojinhoRetangulo(ctx, x, y, l, a, raio) {
  ctx.moveTo(x + raio, y);
  ctx.lineTo(x + l - raio, y);
  ctx.quadraticCurveTo(x + l, y, x + l, y + raio);
  ctx.lineTo(x + l, y + a - raio);
  ctx.quadraticCurveTo(x + l, y + a, x + l - raio, y + a);
  ctx.lineTo(x + raio, y + a);
  ctx.quadraticCurveTo(x, y + a, x, y + a - raio);
  ctx.lineTo(x, y + raio);
  ctx.quadraticCurveTo(x, y, x + raio, y);
}

// o modelo da hora: a hora cheia escolhe um dos doze (o mesmo às 7 e às 19)
function relojinhoModelo(data) {
  return RELOJINHO_MODELOS[data.getHours() % 12];
}

(function () {
  var lugar = document.querySelector(".relojinho canvas");
  var icone = document.querySelector("link[rel=icon]");
  if (!lugar && !icone) {
    return;
  }
  var dois = function (n) { return (n < 10 ? "0" : "") + n; };
  // a barra: o canvas no tamanho do aparelho (nítido nas telas de alta densidade), os segundos andando
  var escala = Math.min(3, window.devicePixelRatio || 1);
  var tamBarra = 30;
  if (lugar) {
    lugar.width = Math.round(tamBarra * escala);
    lugar.height = Math.round(tamBarra * escala);
  }
  var ultimoMinuto = -1;
  var tique = function () {
    var agora = new Date();
    var m = relojinhoModelo(agora);
    if (lugar) {
      var ctx = lugar.getContext("2d");
      ctx.setTransform(escala, 0, 0, escala, 0, 0);
      relojinhoDesenhar(ctx, tamBarra, m, agora, true);
      if (agora.getMinutes() !== ultimoMinuto) {
        lugar.parentNode.setAttribute("title", m.nome + " · " + dois(agora.getHours()) + ":" + dois(agora.getMinutes()));
      }
    }
    // o favicon: um desenho por minuto (a aba mostra a hora certa)
    if (icone && agora.getMinutes() !== ultimoMinuto) {
      var cv = document.createElement("canvas");
      cv.width = 64;
      cv.height = 64;
      relojinhoDesenhar(cv.getContext("2d"), 64, m, agora, false);
      icone.setAttribute("href", cv.toDataURL("image/png"));
    }
    ultimoMinuto = agora.getMinutes();
  };
  tique();
  setInterval(tique, 1000);
})();
