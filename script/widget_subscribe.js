/*-------------------*/
var element = null;

/*-------------------*/
function subscribeLoad() {
  const node = document.querySelector('.subscribe-image');
  node.style.display = "block";
  bounceIn();
}

/*-------------------*/
function bounceIn() {
  animateCSS('.subscribe-image', 'backInUp').then((message) => {
    tada();
  });;
}

/*-------------------*/
function tada() {
  animateCSS('.subscribe-image', 'swing').then((message) => {
    setInterval(() => {
      bounceOut();
    }, 5000);
  });
}

/*-------------------*/
function bounceOut() {
  animateCSS('.subscribe-image', 'backOutDown').then((message) => {
    const node = document.querySelector('.subscribe-image');
    node.style.display = "none";
    if (secsInterval != undefined) {
      setInterval(() => {
        subscribeLoad(); 
      }, 300000);
    }
  });
}

/*-------------------*/
const animateCSS = (element, animation, prefix = 'animate__') =>
  // We create a Promise and return it
  new Promise((resolve, reject) => {
    const animationName = `${prefix}${animation}`;
    const node = document.querySelector(element);

    node.classList.add(`${prefix}animated`, animationName);

    // When the animation ends, we clean the classes and resolve the Promise
    function handleAnimationEnd(event) {
      event.stopPropagation();
      node.classList.remove(`${prefix}animated`, animationName);
      resolve('Animation ended');
    }

    node.addEventListener('animationend', handleAnimationEnd, {once: true});
  });