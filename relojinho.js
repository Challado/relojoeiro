// O reloginho (um easter egg): 24 tipos de relógio, todos diferentes (23 de hoje e um futurista, desenhado à parte no
// relojinhoFuturista), cada um mostrando a hora certa de agora. Um
// fica na barra do menu, no canto esquerdo (os segundos andando; um clique abre a Hora certa; parando o mouse, o modelo e
// a hora), e o outro é o favicon, na aba do navegador (desenhado a cada minuto). Cada hora do dia tem um modelo: no modo
// normal da Configuração, o da hora (à 0 o Clássico, às 16 o Digital, às 23 o Futurista); nos modos aleatório e aleatório sem repetição, o
// que a API sorteou para cada hora do dia (relojinho.ordem, no menu). Tudo em canvas, sem imagem nenhuma; a hora é a do
// aparelho.
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
  {nome: t("Octogonal"), caixa: "octogonal", metal: "#c9ced4", mostrador: "#1d2f5a", indices: "barras", cor_indices: "#f2f5f8", ponteiros: "#f2f5f8", estilo: "baston", brilho: true, data: true, pulseira: "aco", cor_pulseira: "#c9ced4"},
  {nome: t("Fases da lua"), caixa: "redonda", metal: "#b76e79", mostrador: "#16213e", indices: "pontos", cor_indices: "#e8c9a0", ponteiros: "#e8c9a0", estilo: "dauphine", lua: true, pulseira: "couro", cor_pulseira: "#1f2a4a"},
  {nome: t("Calendário"), caixa: "redonda", metal: "#c9ced4", mostrador: "#5a5f66", indices: "barras", cor_indices: "#f4f4f4", ponteiros: "#f4f4f4", estilo: "baston", brilho: true, data: true, pulseira: "milanesa", cor_pulseira: "#b9bec4"},
  {nome: t("Bauhaus"), caixa: "redonda", metal: "#dcdfe3", mostrador: "#f4f1ea", indices: "numeros", cor_indices: "#2b2b2b", ponteiros: "#2b2b2b", estilo: "baston", cor_segundos: "#c8402f", pulseira: "couro", cor_pulseira: "#7a7a7a"},
  {nome: t("Digital"), caixa: "digital", metal: "#1b1d21", mostrador: "#b9c4a3", digital: true, cor_indices: "#1d2418", ponteiros: "#1d2418", pulseira: "borracha", cor_pulseira: "#1b1d21"},
  {nome: t("De bolso"), caixa: "redonda", metal: "#d4af37", mostrador: "#fdfbf4", indices: "romanos", cor_indices: "#1c1c1c", ponteiros: "#1c1c1c", estilo: "espada", subsegundos: true, bolso: true},
  {nome: t("Regulador"), caixa: "redonda", metal: "#c9ced4", mostrador: "#f2efe6", indices: "barras", cor_indices: "#1c2530", ponteiros: "#1f4f8f", estilo: "baston", regulador: true, pulseira: "couro", cor_pulseira: "#3b2416"},
  {nome: t("Ponteiro único"), caixa: "redonda", metal: "#a7adb4", mostrador: "#e9e4d8", indices: "quartos", cor_indices: "#2b2b2b", ponteiros: "#b8402f", estilo: "baston", um_ponteiro: true, pulseira: "couro", cor_pulseira: "#6b4a2b"},
  {nome: t("Turbilhão"), caixa: "redonda", metal: "#e5e4e2", mostrador: "#1b2a4a", indices: "barras", cor_indices: "#e5e4e2", ponteiros: "#e5e4e2", estilo: "dauphine", turbilhao: true, pulseira: "couro", cor_pulseira: "#101a2e"},
  {nome: t("Joia"), caixa: "redonda", metal: "#e8c4b8", mostrador: "#f6f0f4", indices: "pontos", cor_indices: "#c9a0b4", ponteiros: "#8a6a7a", estilo: "dauphine", brilho: true, diamantes: true, pulseira: "aco", cor_pulseira: "#e8c4b8"},
  {nome: t("Madeira"), caixa: "redonda", metal: "#7a4a24", mostrador: "#d2a46c", indices: "barras", cor_indices: "#4a2a12", ponteiros: "#2a1608", estilo: "baston", madeira: true, pulseira: "madeira", cor_pulseira: "#8b5a2b"},
  {nome: t("Futurista"), futurista: true},
];

// Desenha um modelo num contexto 2D, num quadrado de lado tam, na hora dada (com ou sem o ponteiro dos segundos)
function relojinhoDesenhar(ctx, tam, m, data, segundos) {
  if (m.futurista) {
    relojinhoFuturista(ctx, tam, data, segundos);
    return;
  }
  var c = tam / 2;
  var r = tam * 0.34;
  ctx.clearRect(0, 0, tam, tam);
  // a pulseira: duas tiras atrás da caixa
  var lp = tam * 0.3;
  if (!m.bolso) {
    ctx.fillStyle = m.cor_pulseira;
    ctx.fillRect(c - lp / 2, 0, lp, tam);
  }
  if (m.bolso) {
    // o de bolso: sem pulseira; a argola em cima e a corrente saindo dela
    ctx.strokeStyle = m.metal;
    ctx.lineWidth = Math.max(1, tam * 0.025);
    ctx.beginPath();
    ctx.arc(c, tam * 0.07, tam * 0.05, 0, Math.PI * 2);
    ctx.stroke();
    ctx.setLineDash([Math.max(1, tam * 0.03), Math.max(1, tam * 0.02)]);
    ctx.lineWidth = Math.max(1, tam * 0.018);
    ctx.beginPath();
    ctx.moveTo(c - tam * 0.05, tam * 0.07);
    ctx.quadraticCurveTo(tam * 0.12, tam * 0.1, tam * 0.04, tam * 0.5);
    ctx.stroke();
    ctx.setLineDash([]);
  } else if (m.pulseira === "madeira") {
    ctx.strokeStyle = "rgba(0, 0, 0, .35)";
    ctx.lineWidth = Math.max(1, tam * 0.015);
    for (var ym = tam * 0.08; ym < tam; ym += tam * 0.14) {
      ctx.beginPath();
      ctx.moveTo(c - lp / 2, ym);
      ctx.lineTo(c + lp / 2, ym);
      ctx.stroke();
    }
  } else if (m.pulseira === "nato") {
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
  } else if (m.pulseira === "milanesa") {
    // a malha: riscos finos cruzados
    ctx.save();
    ctx.beginPath();
    ctx.rect(c - lp / 2, 0, lp, tam);
    ctx.clip();
    ctx.strokeStyle = "rgba(0, 0, 0, .22)";
    ctx.lineWidth = Math.max(0.5, tam * 0.008);
    for (var d = -tam; d < tam * 2; d += tam * 0.05) {
      ctx.beginPath();
      ctx.moveTo(c - lp / 2, d);
      ctx.lineTo(c + lp / 2, d + lp);
      ctx.moveTo(c - lp / 2, d + lp);
      ctx.lineTo(c + lp / 2, d);
      ctx.stroke();
    }
    ctx.restore();
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
  if (m.bolso) {
    ctx.fillRect(c - tam * 0.035, tam * 0.1, tam * 0.07, tam * 0.06);
  } else {
    ctx.fillRect(c + r + tam * 0.05, c - tam * 0.035, tam * 0.06, tam * 0.07);
  }
  // a caixa e o mostrador
  var forma = function (raio, aro) {
    ctx.beginPath();
    if (m.caixa === "redonda") {
      ctx.arc(c, c, raio, 0, Math.PI * 2);
    } else if (m.caixa === "quadrada") {
      relojinhoRetangulo(ctx, c - raio, c - raio, raio * 2, raio * 2, raio * 0.3);
    } else if (m.caixa === "retangular") {
      relojinhoRetangulo(ctx, c - raio * 0.8, c - raio * 1.05, raio * 1.6, raio * 2.1, raio * 0.12);
    } else if (m.caixa === "digital") {
      relojinhoRetangulo(ctx, c - raio * 1.08, c - raio * 0.88, raio * 2.16, raio * 1.76, raio * 0.2);
    } else if (m.caixa === "octogonal") {
      for (var o = 0; o < 8; o++) {
        var ao = Math.PI / 8 + o * Math.PI / 4;
        ctx[o === 0 ? "moveTo" : "lineTo"](c + Math.cos(ao) * raio * 1.05, c + Math.sin(ao) * raio * 1.05);
      }
    } else {
      relojinhoRetangulo(ctx, c - raio, c - raio, raio * 2, raio * 2, raio * 0.55);
    }
    ctx.closePath();
  };
  forma(r + tam * 0.06);
  ctx.fillStyle = m.metal;
  ctx.fill();
  var veios = function (raio, cor) {
    ctx.save();
    forma(raio);
    ctx.clip();
    ctx.strokeStyle = cor;
    ctx.lineWidth = Math.max(0.5, tam * 0.008);
    for (var v = -raio; v < raio; v += tam * 0.045) {
      ctx.beginPath();
      ctx.moveTo(c - raio, c + v);
      ctx.bezierCurveTo(c - raio * 0.3, c + v - tam * 0.03, c + raio * 0.3, c + v + tam * 0.03, c + raio, c + v);
      ctx.stroke();
    }
    ctx.restore();
  };
  if (m.madeira) {
    veios(r + tam * 0.06, "rgba(0, 0, 0, .22)");
  }
  if (m.diamantes) {
    ctx.fillStyle = "#ffffff";
    ctx.strokeStyle = "rgba(120, 90, 110, .5)";
    ctx.lineWidth = Math.max(0.5, tam * 0.005);
    for (var dd = 0; dd < 24; dd++) {
      var ad = dd * Math.PI / 12;
      ctx.beginPath();
      ctx.arc(c + Math.sin(ad) * (r + tam * 0.03), c - Math.cos(ad) * (r + tam * 0.03), tam * 0.017, 0, Math.PI * 2);
      ctx.fill();
      ctx.stroke();
    }
  }
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
  if (m.digital) {
    // o visor de cristal líquido: HH:MM grande e os segundos pequenos, sem ponteiros
    var d2 = function (n) { return (n < 10 ? "0" : "") + n; };
    ctx.fillStyle = m.cor_indices;
    ctx.textAlign = "center";
    ctx.textBaseline = "middle";
    ctx.font = "bold " + Math.round(r * 0.62) + "px 'Courier New', monospace";
    ctx.fillText(d2(data.getHours()) + ":" + d2(data.getMinutes()), c, c + r * 0.05);
    if (segundos) {
      ctx.font = "bold " + Math.round(r * 0.3) + "px 'Courier New', monospace";
      ctx.fillText(d2(data.getSeconds()), c + r * 0.62, c - r * 0.48);
    }
    return;
  }
  if (m.madeira) {
    veios(r, "rgba(80, 45, 15, .22)");
  }
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
    ctx.fillStyle = m.cor_subdiais || "#d9d5cb";
    [[-0.45, 0], [0.45, 0], [0, 0.45]].forEach(function (e) {
      ctx.beginPath();
      ctx.arc(c + e[0] * r, c + e[1] * r, r * 0.17, 0, Math.PI * 2);
      ctx.fill();
    });
  }
  var sub = function (cy, raio, ang, comp, cor) {
    ctx.strokeStyle = m.cor_indices;
    ctx.lineWidth = Math.max(0.5, tam * 0.008);
    ctx.beginPath();
    ctx.arc(c, cy, raio, 0, Math.PI * 2);
    ctx.stroke();
    ctx.save();
    ctx.translate(c, cy);
    ctx.rotate(ang);
    ctx.strokeStyle = cor;
    ctx.lineWidth = Math.max(1, tam * 0.012);
    ctx.beginPath();
    ctx.moveTo(0, raio * 0.2);
    ctx.lineTo(0, -raio * comp);
    ctx.stroke();
    ctx.restore();
  };
  if (m.subsegundos || m.regulador) {
    sub(c + r * 0.45, r * 0.24, data.getSeconds() * Math.PI / 30, 0.85, m.ponteiros);
  }
  if (m.regulador) {
    sub(c - r * 0.45, r * 0.26, (data.getHours() % 12 + data.getMinutes() / 60) * Math.PI / 6, 0.7, m.ponteiros);
  }
  if (m.turbilhao) {
    // a abertura no 6 e a gaiola girando uma volta por minuto
    var ty = c + r * 0.45;
    var tr = r * 0.27;
    ctx.fillStyle = "#0a0f1c";
    ctx.beginPath();
    ctx.arc(c, ty, tr, 0, Math.PI * 2);
    ctx.fill();
    ctx.strokeStyle = m.metal;
    ctx.lineWidth = Math.max(0.5, tam * 0.01);
    ctx.stroke();
    ctx.save();
    ctx.translate(c, ty);
    ctx.rotate((data.getSeconds() + data.getMilliseconds() / 1000) * Math.PI / 30);
    ctx.strokeStyle = "#c9a54a";
    for (var tb = 0; tb < 3; tb++) {
      ctx.rotate(Math.PI * 2 / 3);
      ctx.beginPath();
      ctx.moveTo(0, 0);
      ctx.lineTo(0, -tr * 0.8);
      ctx.stroke();
    }
    ctx.beginPath();
    ctx.arc(0, 0, tr * 0.45, 0, Math.PI * 2);
    ctx.stroke();
    ctx.restore();
  }
  if (m.lua) {
    // a fase da lua: uma janela em arco no 6, o céu e a lua (a fase de hoje, contada da lua nova de 6/1/2000)
    var ly = c + r * 0.42;
    var lr = r * 0.26;
    ctx.save();
    ctx.beginPath();
    ctx.arc(c, ly, lr, Math.PI, 0);
    ctx.closePath();
    ctx.fillStyle = "#3b5ba5";
    ctx.fill();
    ctx.strokeStyle = m.cor_indices;
    ctx.lineWidth = Math.max(1, tam * 0.012);
    ctx.stroke();
    ctx.clip();
    var fase = ((data.getTime() - Date.UTC(2000, 0, 6, 18, 14)) / 86400000 / 29.530588) % 1;
    ctx.fillStyle = "#f6d86b";
    ctx.beginPath();
    ctx.arc(c + (fase - 0.5) * lr * 2.2, ly - lr * 0.5, lr * 0.46, 0, Math.PI * 2);
    ctx.fill();
    ctx.restore();
  }
  if (m.data) {
    // a janela da data, no 3: o dia do mês
    var dl = r * 0.34;
    var da = r * 0.26;
    var dx = c + r * 0.62;
    ctx.fillStyle = "#f7f7f2";
    ctx.fillRect(dx - dl / 2, c - da / 2, dl, da);
    ctx.fillStyle = "#1c1c1c";
    ctx.font = "bold " + Math.round(r * 0.22) + "px Arial, sans-serif";
    ctx.textAlign = "center";
    ctx.textBaseline = "middle";
    ctx.fillText(String(data.getDate()), dx, c + r * 0.015);
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
    if ((m.data && h === 3) || ((m.lua || m.subsegundos || m.regulador || m.turbilhao) && h === 6) || (m.regulador && h === 0)) {
      // o lugar da data e o da lua ficam sem índice
    } else if (m.triangulo && h === 0) {
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
    } else if (m.indices === "quartos") {
      // o ponteiro único: as horas e, entre elas, os quartos de hora
      ctx.lineWidth = Math.max(1, r * (grande ? 0.07 : 0.045));
      ctx.beginPath();
      ctx.moveTo(c + Math.sin(a) * ri * (grande ? 0.72 : 0.78), c - Math.cos(a) * ri * (grande ? 0.72 : 0.78));
      ctx.lineTo(x1, y1);
      ctx.stroke();
      ctx.lineWidth = Math.max(0.5, r * 0.02);
      for (var q = 1; q < 4; q++) {
        var aq = a + q * Math.PI / 24;
        ctx.beginPath();
        ctx.moveTo(c + Math.sin(aq) * ri * 0.86, c - Math.cos(aq) * ri * 0.86);
        ctx.lineTo(c + Math.sin(aq) * ri * 0.92, c - Math.cos(aq) * ri * 0.92);
        ctx.stroke();
      }
    } else if (m.indices === "romanos" && grande) {
      ctx.font = Math.round(r * (h === 0 ? 0.3 : 0.34)) + "px Georgia, 'Times New Roman', serif";
      ctx.textAlign = "center";
      ctx.textBaseline = "middle";
      ctx.fillText(["XII", "III", "VI", "IX"][h / 3], c + Math.sin(a) * ri * 0.74, c - Math.cos(a) * ri * 0.74 + r * 0.02);
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
  // o regulador tem as horas no submostrador; o ponteiro único só tem o das horas, comprido
  if (m.um_ponteiro) {
    ponteiro(hh * Math.PI / 6, r * 0.86, Math.max(1.2, r * 0.06), m.ponteiros);
  } else {
    if (!m.regulador) {
      ponteiro(hh * Math.PI / 6, r * 0.55, Math.max(1.5, r * 0.09), m.ponteiros);
    }
    ponteiro(mm * Math.PI / 30, r * 0.82, Math.max(1.2, r * 0.065), m.ponteiros);
  }
  if (m.gmt) {
    ponteiro((data.getHours() + data.getMinutes() / 60) * Math.PI / 12, r * 0.72, Math.max(1, r * 0.04), m.gmt);
  }
  // o ponteiro dos segundos (nos de submostrador e no ponteiro único, não tem)
  if (segundos && !m.subsegundos && !m.regulador && !m.um_ponteiro && !m.turbilhao) {
    ctx.save();
    ctx.translate(c, c);
    ctx.rotate(data.getSeconds() * Math.PI / 30);
    ctx.strokeStyle = m.cor_segundos || m.solar || m.gmt || (m.anel ? m.anel : "#c8402f");
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

// O Futurista (o 24º): sem pulseira, colado na pele (a borda cor de pele com os circuitos), a caixa prateada em forma de
// seixo com o miolo de carbono, o mostrador redondo com os anéis de luz ciano e magenta, a carinha de uma IA (que pisca a
// cada 8 segundos), a data, a hora, o coração, o sinal, as barras da voz, as luzes em cima e embaixo, o hexágono no alto e
// os dois hologramas flutuando dos lados
function relojinhoFuturista(ctx, tam, data, segundos) {
  var c = tam / 2;
  var u = tam / 100;
  var ciano = "#33e8ff";
  var magenta = "#ff4fd8";
  var d2 = function (n) { return (n < 10 ? "0" : "") + n; };
  ctx.clearRect(0, 0, tam, tam);
  // a forma de seixo: um oval no meio e uma faixa de pontas arredondadas em cima e embaixo, juntos (um caminho só)
  var seixo = function (l, a) {
    ctx.beginPath();
    ctx.ellipse(c, c, l, a * 0.6, 0, 0, Math.PI * 2);
    relojinhoRetangulo(ctx, c - l * 0.62, c - a, l * 1.24, a * 2, l * 0.45);
  };
  seixo(41 * u, 49 * u);
  ctx.fillStyle = "#b9826c";
  ctx.fill();
  seixo(38.5 * u, 46.5 * u);
  ctx.fillStyle = "#262a31";
  ctx.fill();
  // os circuitos na faixa escura: trilhas com uma dobra e um ponto, presas na faixa (a caixa cobre o resto)
  ctx.save();
  ctx.clip();
  ctx.strokeStyle = "rgba(200, 225, 240, .75)";
  ctx.fillStyle = "rgba(200, 225, 240, .75)";
  ctx.lineWidth = Math.max(0.4, 0.5 * u);
  for (var k = 0; k < 36; k++) {
    var ak = k * Math.PI * 2 / 36;
    var dobra = (k % 2 ? 1 : -1) * 0.08;
    var p1 = [c + Math.cos(ak) * 30 * u, c + Math.sin(ak) * 30 * u];
    var p2 = [c + Math.cos(ak) * 40 * u, c + Math.sin(ak) * 40 * u];
    var p3 = [c + Math.cos(ak + dobra) * 47 * u, c + Math.sin(ak + dobra) * 47 * u];
    ctx.beginPath();
    ctx.moveTo(p1[0], p1[1]);
    ctx.lineTo(p2[0], p2[1]);
    ctx.lineTo(p3[0], p3[1]);
    ctx.stroke();
    ctx.beginPath();
    ctx.arc(p2[0], p2[1], Math.max(0.4, 0.7 * u), 0, Math.PI * 2);
    ctx.fill();
  }
  ctx.restore();
  // a caixa prateada e o miolo de carbono
  var prata = ctx.createLinearGradient(c - 38 * u, c - 40 * u, c + 38 * u, c + 40 * u);
  prata.addColorStop(0, "#eef1f4");
  prata.addColorStop(0.5, "#9aa1aa");
  prata.addColorStop(1, "#d6dadf");
  seixo(32 * u, 41 * u);
  ctx.fillStyle = prata;
  ctx.fill();
  ctx.fillStyle = "#16191e";
  ctx.beginPath();
  relojinhoRetangulo(ctx, c - 21 * u, c - 37 * u, 42 * u, 74 * u, 18 * u);
  ctx.fill();
  ctx.strokeStyle = "rgba(255, 255, 255, .05)";
  ctx.lineWidth = Math.max(0.4, 0.6 * u);
  for (var q = -36; q < 37; q += 3) {
    ctx.beginPath();
    ctx.moveTo(c - 20 * u, c + q * u);
    ctx.lineTo(c + 20 * u, c + q * u);
    ctx.stroke();
  }
  // as luzes em cima e embaixo, e o hexágono no alto
  ctx.save();
  ctx.shadowColor = "#ffffff";
  ctx.shadowBlur = 2 * u;
  ctx.fillStyle = "#f4f8ff";
  [-29, 31].forEach(function (y) {
    ctx.beginPath();
    relojinhoRetangulo(ctx, c - 7 * u, c + y * u, 14 * u, 1.4 * u, 0.7 * u);
    ctx.fill();
  });
  ctx.restore();
  ctx.strokeStyle = "#7a8cff";
  ctx.lineWidth = Math.max(0.4, 0.5 * u);
  ctx.beginPath();
  for (var h6 = 0; h6 < 6; h6++) {
    var a6 = Math.PI / 6 + h6 * Math.PI / 3;
    ctx[h6 === 0 ? "moveTo" : "lineTo"](c + Math.cos(a6) * 2.6 * u, c - 33 * u + Math.sin(a6) * 2.6 * u);
  }
  ctx.closePath();
  ctx.stroke();
  // o mostrador e os anéis de luz (o magenta um pouco torto), e o anel dos segundos que se enche
  var md = c - 1 * u;
  ctx.fillStyle = "#0a1330";
  ctx.beginPath();
  ctx.arc(c, md, 25 * u, 0, Math.PI * 2);
  ctx.fill();
  ctx.save();
  ctx.shadowBlur = 3 * u;
  ctx.lineWidth = Math.max(0.8, 1.2 * u);
  ctx.shadowColor = ciano;
  ctx.strokeStyle = ciano;
  ctx.beginPath();
  ctx.arc(c, md, 26.5 * u, 0, Math.PI * 2);
  ctx.stroke();
  ctx.shadowColor = magenta;
  ctx.strokeStyle = magenta;
  ctx.lineWidth = Math.max(0.6, 0.8 * u);
  ctx.beginPath();
  ctx.ellipse(c + 0.8 * u, md + 0.6 * u, 28.5 * u, 27 * u, 0.2, 0, Math.PI * 2);
  ctx.stroke();
  var sg = data.getSeconds() + data.getMilliseconds() / 1000;
  ctx.lineWidth = Math.max(0.8, 1.4 * u);
  ctx.beginPath();
  ctx.arc(c, md, 23.5 * u, -Math.PI / 2, -Math.PI / 2 + sg * Math.PI / 30);
  ctx.stroke();
  ctx.restore();
  // os hologramas flutuando dos lados
  ctx.save();
  ctx.shadowBlur = 2 * u;
  [[-39, magenta, ciano], [39, ciano, magenta]].forEach(function (hg) {
    var hx = c + hg[0] * u;
    var hy = c - 18 * u;
    ctx.shadowColor = hg[1];
    ctx.strokeStyle = hg[1];
    ctx.lineWidth = Math.max(0.5, 0.7 * u);
    ctx.beginPath();
    ctx.arc(hx, hy, 6 * u, 0, Math.PI * 2);
    ctx.stroke();
    ctx.strokeStyle = hg[2];
    ctx.beginPath();
    ctx.arc(hx, hy, 8.5 * u, Math.PI * 0.2, Math.PI * 1.1);
    ctx.stroke();
    ctx.fillStyle = hg[2];
    ctx.beginPath();
    ctx.arc(hx, hy, 1.6 * u, 0, Math.PI * 2);
    ctx.fill();
  });
  ctx.restore();
  // a data em cima
  ctx.textAlign = "center";
  ctx.textBaseline = "middle";
  ctx.fillStyle = magenta;
  ctx.font = "bold " + Math.round(6 * u) + "px 'Courier New', monospace";
  ctx.fillText(d2(data.getDate()) + "." + d2(data.getMonth() + 1), c, md - 17 * u);
  // a carinha da IA, num círculo: os olhos (que piscam) e o sorriso
  var fy = md - 3 * u;
  var pisca = data.getSeconds() % 8 === 0 && data.getMilliseconds() < 350;
  ctx.save();
  ctx.shadowColor = ciano;
  ctx.shadowBlur = 2.5 * u;
  ctx.strokeStyle = ciano;
  ctx.fillStyle = ciano;
  ctx.lineWidth = Math.max(0.6, 0.8 * u);
  ctx.beginPath();
  ctx.arc(c, fy, 9.5 * u, 0, Math.PI * 2);
  ctx.stroke();
  [-1, 1].forEach(function (lado) {
    ctx.beginPath();
    if (pisca) {
      ctx.rect(c + lado * 3.4 * u - 1.6 * u, fy - 2.4 * u, 3.2 * u, 0.8 * u);
    } else {
      ctx.ellipse(c + lado * 3.4 * u, fy - 2.4 * u, 1.3 * u, 2 * u, 0, 0, Math.PI * 2);
    }
    ctx.fill();
  });
  ctx.lineWidth = Math.max(0.8, 1.3 * u);
  ctx.lineCap = "round";
  ctx.beginPath();
  ctx.arc(c, fy, 5 * u, Math.PI * 0.2, Math.PI * 0.8);
  ctx.stroke();
  ctx.restore();
  // o coração com o pulso, à esquerda em cima; o sinal, à direita
  ctx.save();
  ctx.translate(c - 13 * u, md - 10 * u);
  ctx.strokeStyle = ciano;
  ctx.lineWidth = Math.max(0.4, 0.5 * u);
  ctx.beginPath();
  ctx.moveTo(0, 2 * u);
  ctx.bezierCurveTo(-3.2 * u, -0.5 * u, -1.6 * u, -3 * u, 0, -1.2 * u);
  ctx.bezierCurveTo(1.6 * u, -3 * u, 3.2 * u, -0.5 * u, 0, 2 * u);
  ctx.stroke();
  ctx.strokeStyle = magenta;
  ctx.beginPath();
  ctx.moveTo(-2.2 * u, 0);
  ctx.lineTo(-0.8 * u, 0);
  ctx.lineTo(-0.2 * u, -1.2 * u);
  ctx.lineTo(0.5 * u, 0.9 * u);
  ctx.lineTo(1 * u, 0);
  ctx.lineTo(2.2 * u, 0);
  ctx.stroke();
  ctx.restore();
  ctx.fillStyle = ciano;
  for (var sb = 0; sb < 4; sb++) {
    ctx.fillRect(c + (11 + sb * 1.4) * u, md - 9 * u - sb * 0.9 * u, 0.9 * u, (1.2 + sb * 0.9) * u);
  }
  // as barras da voz, dos dois lados da carinha, subindo e descendo com os segundos
  ctx.fillStyle = magenta;
  [-1, 1].forEach(function (lado) {
    for (var vb = 0; vb < 3; vb++) {
      var nb = 2 + Math.round(3 * Math.abs(Math.sin((data.getSeconds() + vb * 2 + (lado > 0 ? 5 : 0)) * 1.1)));
      for (var bl = 0; bl < nb; bl++) {
        ctx.fillRect(c + lado * (14.5 + vb * 1.8) * u - 0.7 * u, fy + 3 * u - bl * 1.6 * u, 1.3 * u, 1.1 * u);
      }
    }
  });
  // as marcas dos lados e a linha de cima da hora
  ctx.fillStyle = ciano;
  [[-21, 1], [21, -1]].forEach(function (mk) {
    ctx.beginPath();
    ctx.moveTo(c + mk[0] * u, md + 3 * u);
    ctx.lineTo(c + (mk[0] + mk[1] * 2.2) * u, md + 1.8 * u);
    ctx.lineTo(c + (mk[0] + mk[1] * 2.2) * u, md + 4.2 * u);
    ctx.closePath();
    ctx.fill();
  });
  ctx.strokeStyle = "rgba(51, 232, 255, .6)";
  ctx.lineWidth = Math.max(0.4, 0.4 * u);
  ctx.beginPath();
  ctx.moveTo(c - 14 * u, md + 7.5 * u);
  ctx.lineTo(c + 14 * u, md + 7.5 * u);
  ctx.stroke();
  // a hora, grande
  ctx.save();
  ctx.shadowColor = ciano;
  ctx.shadowBlur = 2 * u;
  ctx.fillStyle = ciano;
  ctx.font = "bold " + Math.round(11 * u) + "px 'Courier New', monospace";
  ctx.fillText(d2(data.getHours()) + ":" + d2(data.getMinutes()), c, md + 14 * u);
  ctx.restore();
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

// o modelo da hora: a ordem da API (RELOJINHO_ORDEM, o modelo de cada hora do dia, no modo da Configuração) ou, sem ela
// (antes do menu chegar, ou na página de entrar), a própria hora
var RELOJINHO_ORDEM = window.RELOJINHO_ORDEM || null;
function relojinhoModelo(data) {
  var i = RELOJINHO_ORDEM && RELOJINHO_ORDEM.length === 24 ? RELOJINHO_ORDEM[data.getHours()] : data.getHours();
  return RELOJINHO_MODELOS[i % RELOJINHO_MODELOS.length];
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
  var ultimoModelo = null;
  var tique = function () {
    var agora = new Date();
    var m = relojinhoModelo(agora);
    // o modelo mudou (a ordem da API chegou): o título e o favicon se refazem já, sem esperar o minuto virar
    if (m !== ultimoModelo) {
      ultimoMinuto = -1;
      ultimoModelo = m;
    }
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
