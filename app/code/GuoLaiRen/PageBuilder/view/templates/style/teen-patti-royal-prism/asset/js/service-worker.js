var CACHE_VERSION = 'v5'; 
  // Check if the browser supports SW
  console.log("navigator.serviceWorker:", navigator.serviceWorker);
  if (navigator.serviceWorker != null) {
    navigator.serviceWorker.register("/sw.js?v="+CACHE_VERSION).then(function (registartion) {
      console.log("enable sw:", registartion.scope);
    });
  } else {
    console.log("not enable sw");
  }