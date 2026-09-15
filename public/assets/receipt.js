'use strict';
const printButton = document.getElementById('receipt-print');
if (printButton) printButton.onclick = () => window.print();
const retryButton = document.getElementById('receipt-retry');
if (retryButton) retryButton.onclick = () => location.reload();
document.getElementById('receipt-close').onclick = () => window.close();
window.addEventListener('load', () => {
  if (document.body.dataset.receiptReady === 'true') window.print();
}, {once:true});
