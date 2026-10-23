// Development only: used to build assets/css/tailwind.css (see README). Not needed on the server.
module.exports = {
  content: ['./*.php', './includes/*.php', './api/*.php', './assets/js/*.js'],
  theme: { extend: { colors: { mistnavy: '#262262', mistgold: '#FFC000', mistgreen: '#6dad13' } } },
  plugins: [],
};
