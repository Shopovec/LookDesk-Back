window.onload = () => {

  const ui = SwaggerUIBundle({
    url: "/swagger.json",
    dom_id: "#swagger-ui",

    // Bearer Token Authorization
    presets: [
      SwaggerUIBundle.presets.apis,
      SwaggerUIStandalonePreset
    ],

    layout: "StandaloneLayout",

    requestInterceptor: (req) => {
      const token = localStorage.getItem("api_token");
      if (token) {
        req.headers["Authorization"] = `Bearer ${token}`;
      }
      return req;
    },

    // Try-it-out enabled automatically
    tryItOutEnabled: true,
  });

  window.ui = ui;
};
