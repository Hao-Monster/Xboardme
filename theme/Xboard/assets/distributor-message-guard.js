(function () {
  'use strict';

  const DISTRIBUTOR_ACCESS_DENIED = '分销商账号无权访问该功能';
  let messageService = window.$message;

  function guard(service) {
    if (!service || typeof service.error !== 'function' || service.__distributorMessageGuard) {
      return service;
    }

    const showError = service.error.bind(service);
    service.error = function (message, ...options) {
      if (message === DISTRIBUTOR_ACCESS_DENIED) return undefined;
      return showError(message, ...options);
    };
    Object.defineProperty(service, '__distributorMessageGuard', { value: true });
    return service;
  }

  Object.defineProperty(window, '$message', {
    configurable: true,
    enumerable: true,
    get() {
      return messageService;
    },
    set(service) {
      messageService = guard(service);
    },
  });

  messageService = guard(messageService);
})();
