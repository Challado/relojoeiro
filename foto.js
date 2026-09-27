// Reduz a foto no próprio navegador antes de enviar: no máximo 1200 px, JPEG 85%.
// Foto de celular tem vários MB; assim chega com poucas centenas de KB e cabe no limite de upload do PHP.
// Escuta no documento inteiro, para funcionar também no painel que é carregado depois.
document.addEventListener("change", function (ev) {
  var campo = ev.target;
  if (campo.matches("input[type=file][data-foto]") && campo.files[0]) {
    var destino = document.getElementById(campo.getAttribute("data-foto"));
    var previa = document.getElementById(campo.getAttribute("data-previa"));
    var img = new Image();
    img.onload = function () {
      var escala = Math.min(1, 1200 / Math.max(img.width, img.height));
      var c = document.createElement("canvas");
      c.width = Math.round(img.width * escala);
      c.height = Math.round(img.height * escala);
      c.getContext("2d").drawImage(img, 0, 0, c.width, c.height);
      destino.value = c.toDataURL("image/jpeg", 0.85);
      if (previa) {
        previa.src = destino.value;
        previa.hidden = false;
      }
      URL.revokeObjectURL(img.src);
    };
    img.onerror = function () {
      alert("Não consegui abrir essa imagem. Use JPEG ou PNG.");
    };
    img.src = URL.createObjectURL(campo.files[0]);
  }
});
