const { authenticateLocalBowenQa } = require('./bowen-qa-auth.cjs');

module.exports = async () => {
  await authenticateLocalBowenQa();
};
