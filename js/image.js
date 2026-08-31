//batch 2


// Get the return parameter from the URL (defaults to 'signup' if missing)
const urlParams = new URLSearchParams(window.location.search);
const returnPage = urlParams.get('return') || 'signup';



function load() {
    if (human) {
        //human.style.backgroundImage = "url(img/Profile_Highlight_70x70.png)";
        human.style.backgroundRepeat = "no-repeat";
        human.style.backgroundPosition = "50% 50%";
        human.style.backgroundSize = "100% 100%";
    }
    //===============================================
    rotate=document.getElementById("rotate");
    if (rotate) {
        rotate.src="img/Rotate_Icon_70x70.png";
    }
    rotatespan=document.getElementById("rotatespan");
    //===============================================
    reset=document.getElementById("reset");
    if (reset) {
        reset.src="img/Reset_Icon_70x70.png";
    }
    resetspan=document.getElementById("resetspan");
    //===============================================
    returnit=document.getElementById("return");
    if (returnit) {
        returnit.src="img/Return_Icon_70x70.png";
    }
    returnspan=document.getElementById("returnspan");
    //===============================================
}



/////////////////////////////////////////////
// check if storage works (which is necessary for the program to work
/////////////////////////////////////////////
var storage;
var fail;
var uid;
try {
	uid = new Date;
	(storage = window.localStorage).setItem(uid, uid);
	fail = storage.getItem(uid) != uid;
	storage.removeItem(uid);
	fail && (storage = false);
} catch (exception) {};

/////////////////////////////////////////////
// install global vars
/////////////////////////////////////////////
var canvas = document.getElementsByTagName('canvas');
//var canvas[0] = document.getElementById("canvas");
var ctx = canvas[0].getContext("2d");
canvas[0].width = 350; //window.innerWidth;
canvas[0].height = 250; //window.innerHeight;
//var canvas[1] = document.getElementById("canvas2");
var ctx2 = canvas[1].getContext("2d");
canvas[1].width = 350; //window.innerWidth;
canvas[1].height = 250; //window.innerHeight;


var touchX,touchY,otx,oty, ttx, tty;
var ww = 350;
var hh = 250;
var br = 100.0;
var co = 100.0;
var sa = 100.0;


var img = new Image;
var canvasWidth=canvas[0].width;
var canvasHeight=canvas[0].height;
var imageWidth=0;
var imageHeight=0;
var rot = 0;
var focx = 0; //this is the x focuspoint where the image is centered in the paspartout the left top of the image is 0
var focy = 0; //this is the y focuspoint where the image is centered in the paspartout the left top of the image is 0
var ox=0;
var oy=0;
var imw=0;
var imh=0;
var image1 = new Image;
var pchoice = "0";
var imb1 = false;
var preload = true;
// --- NEW SOURCE STATE TRACKER ---
var imageSourceType = "file"; // Tracking variable: holds either "file" or "webcam"


const load_button = document.getElementById("loadpicture"); //is the invisible button
//const load2_button = document.getElementById("loadimage");  //is the dummy button that activates the invisible button
const rotate_button = document.getElementById("text_04");
const reset_button = document.getElementById("text_05");
const return_button = document.getElementById("text_06");
const save_button = document.getElementById("text_02"); //is the Load New Image button when screen is opened
const load_image = document.getElementById("loadimage"); //is the Load New Image button when screen is opened
const slide_button = document.getElementById("myRange");
const slide_button1 = document.getElementById("myRange1");
const slide_button2 = document.getElementById("myRange2");
const slide_button3 = document.getElementById("myRange3");
// const lightIcons = document.getElementById("lightIcons");
const zoomin_button = document.getElementById("zoomin");
const zoomout_button = document.getElementById("zoomout");
const morecontrast_button = document.getElementById("contrastmax");
const lesscontrast_button = document.getElementById("contrastmin");
const morebrightness_button = document.getElementById("brightnessmax");
const lessbrightness_button = document.getElementById("brightnessmin");
const morecolor_button = document.getElementById("colormax");
const lesscolor_button = document.getElementById("colormin");
const cancel_button = document.getElementById("cancelBtn");


var mx1=0;
var my1=0;
var mx2=0;
var my2=0;
var isDragging=false;
var portx1 = 0;
var porty1 = 0;
var portx2 = 0;
var porty2 = 0;
var scale = 100;
var scale2 = 100;
var picsize = 1;
var pchoice = 0;
var touchX,touchY;


//=======================================================
//     Function to redraw the background of the image
//=======================================================          
function drawBack() {
  if (!(preload)) {
    /////////////////////////////////////////////////
    ctx.clearRect(0,0,canvasWidth,canvasHeight);
    // ctx.strokeStyle = "rgb(255, 0, 0)";
    // ctx.fillStyle = "white"; //"rgba(0, 0, 0, 1.0)"; #292C34
    ctx.strokeStyle = "rgb(0, 0, 0)";
    ctx.fillStyle = "rgba(0, 0, 0, 1.0)";
    ctx.fillRect(0,0,ww,hh);
    /////////////////////////////////////////////////
    ctx2.clearRect(0,0,canvasWidth,canvasHeight);
    // ctx2.strokeStyle = "rgb(255, 0, 0)";
    // ctx2.fillStyle = "white"; //"rgba(0, 0, 0, 1.0)"; #292C34
    ctx2.strokeStyle = "rgb(0, 0, 0)";
    ctx2.fillStyle = "rgba(0, 0, 0, 1.0)";
    ctx2.fillRect(0,0,ww,hh);
    /////////////////////////////////////////////////
  }  
}


//batch 3


//=======================================================
//     Function to redraw the paspartout
//=======================================================          
function drawPaspartout() {
  if (!(preload)) {
    ctx.fillStyle = "rgba(2, 2, 2, .8)";
    ctx.fillRect(0,0,ww,porty1);
    ctx.fillRect(0,porty1,portx1,porty2-porty1);
    ctx.fillRect(portx2,porty1,ww-portx2,porty2-porty1);
    ctx.fillRect(0,porty2,ww,hh-porty2);
    ctx.beginPath();
    ctx.lineWidth = 2;
    ctx.strokeStyle = "red";
    ctx.moveTo(portx1-1, porty1+40);
    ctx.lineTo(portx1-1, porty1-1);
    ctx.lineTo(portx1+40, porty1-1);
    ctx.moveTo(portx2-40, porty1-1);
    ctx.lineTo(portx2+1, porty1-1);
    ctx.lineTo(portx2+1, porty1+40);
    ctx.moveTo(portx2+1, porty2-40);
    ctx.lineTo(portx2+1, porty2+1);
    ctx.lineTo(portx2-40, porty2+1);
    ctx.moveTo(portx1+40, porty2+1);
    ctx.lineTo(portx1-1, porty2+1);
    ctx.lineTo(portx1-1, porty2-40);
    ctx.stroke();
  }  
}


//=======================================================
//     Function to redraw the image on ctx and ctx2
//=======================================================          
function drawImg() {
   if (!(preload)) {
      //focx is center focus point
      // var ax = Math.round((ww / 2) - (256 / 2)) - ox;         //257  B/2 - 128
      // var ay = Math.round((hh / 2) - (256 / 2)) - oy;         //128  H/2 - 128
      if (!(rot == 0)) {
          //ctx.save(); 
          ctx2.save(); 
          switch(rot) {
                //case 0: rr = 0;break;
                case 1: 
                {
                   rr = Math.PI * 0.5;
                   //ctx.translate(Math.round((ww / 2) + (hh / 2)) , Math.round((hh / 2) - (ww / 2)));         //641,-129);
                   ctx2.translate(Math.round((ww / 2) + (hh / 2)) , Math.round((hh / 2) - (ww / 2)));         //641,-129);
                   //ctx.rotate(rr);
                   ctx2.rotate(rr);
                   var imw2 = imw;
                   var imh2 = imh;
                   imw=Math.round(imageWidth * scale / 100);
                   imh=Math.round(imageHeight * scale / 100);
                   focx = Math.round(focx *(imw / imw2));
                   focy = Math.round(focy *(imw / imw2));
                   ox = Math.round(ww / 2) - focx;   //385
                   oy = Math.round(hh / 2) - focy;   //256
                   //ctx.drawImage(img,ox,oy,imw,imh);
                   ctx2.drawImage(img,ox,oy,imw,imh);
                   var ccx = (Math.round(ww / 2) + Math.round(hh / 2)) - (oy + imh);
                   var ccy = (Math.round(hh / 2) - Math.round(ww / 2)) + ox;
                   var cwx = imh;
                   var cwy = imw;
                };break;
                case 2: 
                {
                   rr = Math.PI;
                   //ctx.translate(ww,hh);             //770,512);
                   ctx2.translate(ww,hh);             //770,512);
                   //ctx.rotate(rr);
                   ctx2.rotate(rr);
                   var imw2 = imw;
                   var imh2 = imh;
                   imw=Math.round(imageWidth * scale / 100);
                   imh=Math.round(imageHeight * scale / 100);
                   focx = Math.round(focx *(imw / imw2));
                   focy = Math.round(focy *(imw / imw2));
                   ox = Math.round(ww / 2) - focx;   //385
                   oy = Math.round(hh / 2) - focy;   //256
                   //ctx.drawImage(img,ox,oy,imw,imh);
                   ctx2.drawImage(img,ox,oy,imw,imh);
                   var ccx = ww-(ox+imw);
                   var ccy = hh-(oy+imh);
                   var cwx = imw;
                   var cwy = imh;
                };break;           
                case 3: 
                {
                   rr = Math.PI * 1.5;
                   //ctx.translate(Math.round((ww / 2) - (hh / 2)), Math.round((ww / 2) + (hh / 2)));          //  129,641);
                   ctx2.translate(Math.round((ww / 2) - (hh / 2)), Math.round((ww / 2) + (hh / 2)));          //  129,641);
                   //ctx.rotate(rr);
                   ctx2.rotate(rr);
                   var imw2 = imw;
                   var imh2 = imh;
                   imw=Math.round(imageWidth * scale / 100);
                   imh=Math.round(imageHeight * scale / 100);
                   focx = Math.round(focx *(imw / imw2));
                   focy = Math.round(focy *(imw / imw2));
                   ox = Math.round(ww / 2) - focx;   //385
                   oy = Math.round(hh / 2) - focy;   //256
                   //ctx.drawImage(img,ox,oy,imw,imh);
                   ctx2.drawImage(img,ox,oy,imw,imh);
                   var ccx = (Math.round(ww / 2) - Math.round(hh / 2)) + (oy + 0);
                   var ccy = (Math.round(hh / 2) + Math.round(ww / 2)) - (ox + imw);
                   var cwx = imh;
                   var cwy = imw;
                };break;
          };  
          ctx2.restore(); 
          //ctx.restore(); 
      } else
      {
          var imw2 = imw;
          var imh2 = imh;
          imw=Math.round(imageWidth * scale / 100);
          imh=Math.round(imageHeight * scale / 100);   
          focx = Math.round(focx *(imw / imw2));
          focy = Math.round(focy *(imw / imw2));
          ox = Math.round(ww / 2) - focx;   //385
          oy = Math.round(hh / 2) - focy;   //256
          //ctx.drawImage(img,ox,oy,imw,imh);
          ctx2.drawImage(img,ox,oy,imw,imh);
          var ccx = ox;
          var ccy = oy;
          var cwx = imw;
          var cwy = imh;
      }
      if (ccx<0) {cwx=cwx+ccx;ccx=0;if (cwx<0) {cwx = 0;};if (cwx > ww) {cwx = ww-1;};};
      if (ccy<0) {cwy=cwy+ccy;ccy=0;if (cwy<0) {cwy = 0;};if (cwy > hh) {cwy = hh-1;};};
      ////////////////////////////////////////////////////////////////////
      var cssFilter = getComputedStyle(canvas[1]).filter;
      ctx2.filter = cssFilter;
      ctx.drawImage(canvas[1],ccx, ccy, cwx, cwy,ccx, ccy, cwx, cwy);
   }          
}



//batch 4

function handleFile(e) 
{
    ////////////////////////////////////////////////////////////////
    /// This procedure is called after custom-file-upload button
    //////////////////////////////////////////////////////////////// 
    var language = localStorage.getItem("site-language");
    if (e.target.files[0] == null) {
        //alert("No file selected!");
    } else 
    {
        ////////////////////////////////////////////
        ///  this is only called once the image is loaded
        ////////////////////////////////////////////
        img.onload = function() 
        {
            preload = false;
            imageSourceType = "file"; 
            ctx.clearRect(0,0,innerWidth,innerHeight);
            ctx2.clearRect(0,0,innerWidth,innerHeight);
            
            // 1. INITIALIZE ASSET PROPERTIES AT 100% SCALE FIRST
            imageWidth = img.width;
            imageHeight = img.height;
            imw = imageWidth;
            imh = imageHeight;
            
            // Define focus points at the true center of the raw uploaded image
            focx = Math.round(imageWidth / 2);
            focy = Math.round(imageHeight / 2);
            focx0 = focx;
            focy0 = focy; 

            // 2. COMPUTE THE DYNAMIC CONTAINMENT RATIO
            let scaleX = ww / imageWidth;
            let scaleY = hh / imageHeight;
            let baseFitScale = Math.min(scaleX, scaleY) * 100;

            // 3. APPLY THE 80% CENTERING VALUE OVERRIDE
            scale = Math.round(baseFitScale * 0.80); 
            scale2 = 100;
            document.getElementById("myRange").value = 53; 

            // 4. REFRESH AND DRAW
            drawBack();
            drawImg(); // Fires centered matrix math successfully across both context arrays
            drawPaspartout();
            
            // Keep all your standard asset UI tools visibility rules completely intact below this point
            text_02.innerHTML = '<img id="save" src="img/Save_Icon_70x70.png" class="iconclass35" alt="Save" /><span id="savespan" class="image-text">Ready</span>';
            save = document.getElementById("save");
            savespan = document.getElementById("savespan");



            // Existing Part B logic morphing Snap to Abort:
            text_06.innerHTML = '<img id="return" src="img/Return_Icon_70x70.png" class="iconclass35" alt="Abort" /><span id="returnspan" class="image-text">' + (typeof t === 'function' ? t('IMG_ABORT') : 'Abort') + '</span>';
            returnit = document.getElementById("return");
            returnspan = document.getElementById("returnspan");

            // CLEAR DISABLE/LOCALHOST STYLES SO THE ABORT ACTION WORKS!
            return_button.disabled = false;
            return_button.style.backgroundColor = ''; 
            return_button.style.color = '';
            return_button.style.cursor = 'pointer';
            return_button.style.opacity = '1.0';

            // Keep all your existing tools exposure parameters intact below this point
            rotate_button.style.visibility = 'visible';
            reset_button.style.visibility = 'visible';

            return_button.style.visibility = 'visible';
            slide_button.style.visibility = 'visible';
            slide_button1.style.visibility = 'visible';
            slide_button2.style.visibility = 'visible';
            slide_button3.style.visibility = 'visible';
            zoomin_button.style.visibility = 'visible';
            zoomout_button.style.visibility = 'visible';
            morecontrast_button.style.visibility = 'visible';
            lesscontrast_button.style.visibility = 'visible';
            morebrightness_button.style.visibility = 'visible';
            lessbrightness_button.style.visibility = 'visible';
            morecolor_button.style.visibility = 'visible';
            lesscolor_button.style.visibility = 'visible';
        }
        img.onerror = function() 
        {
            alert("Error occurred while loading image");
        }
    }
    /////////////////////////////////////////////
    /// Here the image is loaded
    /////////////////////////////////////////////  
    if (!(e.target.files[0] == null)) 
    {
      /////////////////////////////////////////////
      /// load image
      /////////////////////////////////////////////  
      img.src = URL.createObjectURL(e.target.files[0]);
      /////////////////////////////////////////////
      ///image cant be draw here as picture is not loaded yet
      /////////////////////////////////////////////  
    }  
}


function doMouseDown(e)
{
    if (rotate_button.style.visibility == 'visible')
    {
      mx1=e.clientX;
      my1=e.clientY;
      isDragging=true;
    };  
}

    
function doMouseMove(e)
{
    if (rotate_button.style.visibility == 'visible')
    {
     
      ///////////////////////////////////////
      //only place wher the focus may change;
      /////////////////////////////////////// 
      mx2=e.clientX;
      my2=e.clientY;
      if (isDragging) 
      {
        //var res = String(rr);
        //alert(res);
        switch(rot) {
            case 0: {
               focx = focx + (mx1-mx2);mx1 = mx2;
               focy = focy + (my1-my2);my1 = my2;
               focx0 = focx;
               focy0 = focy;
               };break;
            case 1: {
               focx = focx + (my1-my2);my1 = my2;          //hier is iets fout!!
               focy = focy + (mx2-mx1);mx1 = mx2;
               focx0 = focx;
               focy0 = focy;
               };break;
            case 2: {
               focx = focx - (mx1-mx2);mx1 = mx2;     
               focy = focy - (my1-my2);my1 = my2;
               focx0 = focx;
               focy0 = focy;
               };break;
            case 3: {
               focx = focx - (my1-my2);my1 = my2;       ///hier niet
               focy = focy - (mx2-mx1);mx1 = mx2;
               focx0 = focx;
               focy0 = focy;
               };break;
        };  
        co = Math.round(100 * Math.pow(1.02, (50 - slide_button1.value)));
        br = Math.round(100 * Math.pow(1.02, (50 - slide_button2.value)));
        sa = Math.round(100 * Math.pow(1.02, (50 - slide_button3.value)));
        ctx2.filter = "contrast(" + co.toString() + "%) brightness(" + br.toString() + "%) saturate(" + sa.toString() + "%)";   
        drawBack();
        drawImg(); 
        drawPaspartout();
      };
    };  
}
    
    
function doMouseUp(e)
{
    if (rotate_button.style.visibility == 'visible')
    {
      isDragging=false;
    };  
}


function doMouseOut(e)
{
    if (rotate_button.style.visibility == 'visible')
    {
      isDragging=false;
    };  
}




//batch 5

function sketchpad_touchStart() 
{
    if (rotate_button.style.visibility == 'visible')
    {
        getTouchPos();
        ttx = touchX;
        tty = touchY;
        // Prevents an additional mousedown event being triggered
        event.preventDefault();
    };
}


function sketchpad_touchMove(e) 
{ 
    if (rotate_button.style.visibility == 'visible')
    {
        // Update the touch co-ordinates
        getTouchPos(e);
        // During a touchmove event, unlike a mousemove event, we don't need to check if the touch is engaged, since there will always be contact with the screen by definition.
        otx=touchX;
        oty=touchY;
        switch(rot) 
        {
            case 0: {
               focx = focx + (ttx-otx);ttx = otx;
               focy = focy + (tty-oty);tty = oty;
               focx0 = focx;
               focy0 = focy;
               };break;
            case 1: {
               focx = focx + (tty-oty);tty = oty;          
               focy = focy + (otx-ttx);ttx = otx;
               focx0 = focx;
               focy0 = focy;
               };break;
            case 2: {
               focx = focx - (ttx-otx);ttx = otx;     
               focy = focy - (tty-oty);tty = oty;
               focx0 = focx;
               focy0 = focy;
               };break;
            case 3: {
               focx = focx - (tty-oty);tty = oty;       
               focy = focy - (otx-ttx);ttx = otx;
               focx0 = focx;
               focy0 = focy;
               };break;
          };  
          co = Math.round(100 * Math.pow(1.02, (50 - slide_button1.value)));
          br = Math.round(100 * Math.pow(1.02, (50 - slide_button2.value)));
          sa = Math.round(100 * Math.pow(1.02, (50 - slide_button3.value)));
          ctx2.filter = "contrast(" + co.toString() + "%) brightness(" + br.toString() + "%) saturate(" + sa.toString() + "%)";   
          drawBack();
          drawImg(); 
          drawPaspartout();
        // Prevent a scrolling action as a result of this touchmove triggering.
        event.preventDefault();
    };   
}


function getTouchPos(e) 
{
    if (rotate_button.style.visibility == 'visible')
    {
        if (!e)
            var e = event;
    
        if (e.touches) 
        {
            if (e.touches.length == 1) 
            { // Only deal with one finger
                var touch = e.touches[0]; // Get the information for finger #1
                touchX=touch.pageX-touch.target.offsetLeft;
                touchY=touch.pageY-touch.target.offsetTop;
            }
        }
    };    
}


function rotateIt()
{
    if (rotate_button.style.visibility == 'visible')
    {
        ctx.clearRect(0,0,canvasWidth,canvasHeight);
        ctx2.clearRect(0,0,canvasWidth,canvasHeight);
        rot = rot + 1;
        if (rot == 4) {rot = 0};
        var focxx = focx;
        co = Math.round(100 * Math.pow(1.02, (50 - slide_button1.value)));
        br = Math.round(100 * Math.pow(1.02, (50 - slide_button2.value)));
        sa = Math.round(100 * Math.pow(1.02, (50 - slide_button3.value)));
        ctx2.filter = "contrast(" + co.toString() + "%) brightness(" + br.toString() + "%) saturate(" + sa.toString() + "%)";   
        drawBack();
        drawImg(); 
        drawPaspartout();
    }
}  


function resetslides()
{
    document.getElementById("myRange").value = 50;
    document.getElementById("myRange1").value = 50;
    document.getElementById("myRange2").value = 50;
    document.getElementById("myRange3").value = 50;
    //document.getElementById("canvas").webkitFilter = "brightness(100%) contrast(100%) saturate(100%)";
    //document.getElementById("canvas").style.filter = "brightness(100%) contrast(100%) saturate(100%)";
    co = Math.round(100 * Math.pow(1.02, (50 - slide_button1.value)));
    br = Math.round(100 * Math.pow(1.02, (50 - slide_button2.value)));
    sa = Math.round(100 * Math.pow(1.02, (50 - slide_button3.value)));
    ctx2.filter = "contrast(" + co.toString() + "%) brightness(" + br.toString() + "%) saturate(" + sa.toString() + "%)";   
    scale = 100;
    scale2 = 100;
    drawBack();
    drawImg(); 
    drawPaspartout();
}


function zoomin()
{
    var xx = document.getElementById("myRange").value;
    if (xx>0) {
        xx--;
    }
    var posit = 0;
    posit = xx;
    total = Number(posit);
    if (total < 0) {total = 0;};
    if (total > 100) {total = 100}; 
    scale2=scale;
    scale = (100 * Math.pow(1.07, (50 - total)));
    if (!(scale2==0)) {scale2=(scale / scale2) }; 
    co = Math.round(100 * Math.pow(1.02, (50 - slide_button1.value)));
    br = Math.round(100 * Math.pow(1.02, (50 - slide_button2.value)));
    sa = Math.round(100 * Math.pow(1.02, (50 - slide_button3.value)));
    ctx2.filter = "contrast(" + co.toString() + "%) brightness(" + br.toString() + "%) saturate(" + sa.toString() + "%)";   
    drawBack();
    drawImg(); 
    drawPaspartout();
    document.getElementById("myRange").value = total;
}  



//batch 6

function zoomout()
{
    var xx = document.getElementById("myRange").value;
    if (xx<100) {
        xx++;
    }
    var posit = 0;
    posit = xx;
    total = Number(posit);
    if (total < 0) {total = 0;};
    if (total > 100) {total = 100}; 
    scale2=scale;
    scale = (100 * Math.pow(1.07, (50 - total)));
    if (!(scale2==0)) {scale2=(scale / scale2) }; 
    co = Math.round(100 * Math.pow(1.02, (50 - slide_button1.value)));
    br = Math.round(100 * Math.pow(1.02, (50 - slide_button2.value)));
    sa = Math.round(100 * Math.pow(1.02, (50 - slide_button3.value)));
    ctx2.filter = "contrast(" + co.toString() + "%) brightness(" + br.toString() + "%) saturate(" + sa.toString() + "%)";   
    drawBack();
    drawImg(); 
    drawPaspartout();
    document.getElementById("myRange").value = total;
}


function morecontrast()
{
    var xx = slide_button1.value;
    if (xx>0) {
        xx--;
    }
    co = Math.round(100 * Math.pow(1.02, (50 - xx)));
    br = Math.round(100 * Math.pow(1.02, (50 - slide_button2.value)));
    sa = Math.round(100 * Math.pow(1.02, (50 - slide_button3.value)));
    ctx2.filter = "contrast(" + co.toString() + "%) brightness(" + br.toString() + "%) saturate(" + sa.toString() + "%)";   
    drawBack();
    drawImg(); 
    drawPaspartout();
    slide_button1.value = xx;
}  


function lesscontrast()
{
    var xx = slide_button1.value;
    if (xx<100) {
        xx++;
    }
    co = Math.round(100 * Math.pow(1.02, (50 - xx)));
    br = Math.round(100 * Math.pow(1.02, (50 - slide_button2.value)));
    sa = Math.round(100 * Math.pow(1.02, (50 - slide_button3.value)));
    ctx2.filter = "contrast(" + co.toString() + "%) brightness(" + br.toString() + "%) saturate(" + sa.toString() + "%)";   
    drawBack();
    drawImg(); 
    drawPaspartout();
    slide_button1.value = xx;
}  


function morebrightness()
{
    var xx = slide_button2.value;
    if (xx>0) {
        xx--;
    }
    co = Math.round(100 * Math.pow(1.02, (50 - slide_button1.value)));
    br = Math.round(100 * Math.pow(1.02, (50 - xx)));
    sa = Math.round(100 * Math.pow(1.02, (50 - slide_button3.value)));
    ctx2.filter = "contrast(" + co.toString() + "%) brightness(" + br.toString() + "%) saturate(" + sa.toString() + "%)";   
    drawBack();
    drawImg(); 
    drawPaspartout();
    slide_button2.value = xx;
}  


function lessbrightness()
{
    var xx = slide_button2.value;
    if (xx<100) {
        xx++;
    }
    co = Math.round(100 * Math.pow(1.02, (50 - slide_button1.value)));
    br = Math.round(100 * Math.pow(1.02, (50 - xx)));
    sa = Math.round(100 * Math.pow(1.02, (50 - slide_button3.value)));
    ctx2.filter = "contrast(" + co.toString() + "%) brightness(" + br.toString() + "%) saturate(" + sa.toString() + "%)";   
    drawBack();
    drawImg(); 
    drawPaspartout();
    slide_button2.value = xx;
}  


function morecolor()
{
    var xx = slide_button3.value;
    if (xx>0) {
        xx--;
    }
    co = Math.round(100 * Math.pow(1.02, (50 - slide_button1.value)));
    br = Math.round(100 * Math.pow(1.02, (50 - slide_button2.value)));
    sa = Math.round(100 * Math.pow(1.02, (50 - xx)));
    ctx2.filter = "contrast(" + co.toString() + "%) brightness(" + br.toString() + "%) saturate(" + sa.toString() + "%)";   
    drawBack();
    drawImg(); 
    drawPaspartout();
    slide_button3.value = xx;
}  


function lesscolor()
{
    var xx = slide_button3.value;
    if (xx<100) {
        xx++;
    }
    co = Math.round(100 * Math.pow(1.02, (50 - slide_button1.value)));
    br = Math.round(100 * Math.pow(1.02, (50 - slide_button2.value)));
    sa = Math.round(100 * Math.pow(1.02, (50 - xx)));
    ctx2.filter = "contrast(" + co.toString() + "%) brightness(" + br.toString() + "%) saturate(" + sa.toString() + "%)";   
    drawBack();
    drawImg(); 
    drawPaspartout();
    slide_button3.value = xx;
}


function cancel()
{
    // Basic safety: ensure returnPage only contains letters/numbers
    const safePage = returnPage.replace(/[^a-z0-9-]/gi, '');
    window.open(safePage + ".php", "_self");
}



//batch 7

function myFunction(e) 
{
  if (rotate_button.style.visibility == 'visible')
  {
      /* Check whether the wheel event is supported. */
      if (e.type == "wheel") supportsWheel = true;
      else if (supportsWheel) return;
      /* Determine the direction of the scroll (< 0 ? up, > 0 ? down). */
      var delta = 0;
      delta = ((e.deltaY || -e.wheelDelta || e.detail) >> 10) || 1;
      var posit = 0;
      posit = document.getElementById("myRange").value;
      total = Number(posit) + Number(delta);
      if (total < 0) {total = 0;};
      if (total > 100) {total = 100}; 
      scale2=scale;
      scale = (100 * Math.pow(1.07, (50 - total)));
      if (!(scale2==0)) {scale2=(scale / scale2) }; 
      co = Math.round(100 * Math.pow(1.02, (50 - slide_button1.value)));
      br = Math.round(100 * Math.pow(1.02, (50 - slide_button2.value)));
      sa = Math.round(100 * Math.pow(1.02, (50 - slide_button3.value)));
      ctx2.filter = "contrast(" + co.toString() + "%) brightness(" + br.toString() + "%) saturate(" + sa.toString() + "%)";   
      drawBack();
      drawImg();
      drawPaspartout();
      document.getElementById("myRange").value = total;
   };    
}


function drawOldImage() {
   // Only render the clean fallback image bounding square profile layers if storage variables are active
   if ((pchoice == "1") && (imb1)) {
       if (!image1.complete) {
           setTimeout(function(){ drawOldImage(); }, 50);
           return;
       }
       ctx.drawImage(image1, portx1, porty1);
       ctx2.drawImage(image1, portx1, porty1);
   }
   
   // --- PROPOSED REFINEMENT: "LOAD NEW IMAGE?" TEXT REMOVED ---
   // All old canvas font text fills and positioning calculations deleted to ensure clean viewports!
}



/////////////////////////////////////////////
// Main application - run only once at load screen
/////////////////////////////////////////////
function main() 
{
    /////////////////////////////////////////////
    // load all data if available
    /////////////////////////////////////////////
    if (storage) 
    {
        var language = localStorage.getItem("site-language");
        pchoice = "1";  //localStorage.getItem("p-choice");
        picsize = 1;
        portx1 = Math.round((ww / 2) - (140 / 2));
        porty1 = Math.round((hh / 2) - (140 / 2)); 
        portx2 = Math.round((ww / 2) + (140 / 2));
        porty2 = Math.round((hh / 2) + (140 / 2));
        /////////////////////////////////////////////
        /////////////////////////////////////////////
        //         Redraw canvas 
        /////////////////////////////////////////////
        /////////////////////////////////////////////
        preload = false;
        drawBack();
        drawPaspartout();    
        /////////////////////////////////////////////
        //     load images if existing
        /////////////////////////////////////////////
        switch(pchoice) 
        {
            case "1": {var picture1 = localStorage.getItem('image1');imb1 = (!(picture1 == null));if (imb1) {image1 = document.createElement('img');image1.src = picture1;};};break;
        }  
        //=======================================================
        //     get pictures from localStore (Base64 decoded)
        //=======================================================    
        drawOldImage();      
        //=======================================================    
        load_button.addEventListener('change', handleFile, false);
        
        // Connect the mobile hardware camera element to your primary handling loop
        const cameraInput = document.getElementById("camerapicture");
        if (cameraInput) {
            cameraInput.addEventListener('change', handleFile, false);
        }

        canvas[0].addEventListener("mousedown", doMouseDown, false);
        canvas[0].addEventListener("mousemove", doMouseMove, false);
        canvas[0].addEventListener("mouseup", doMouseUp, false);
        canvas[0].addEventListener("mouseout", doMouseOut, false);
        canvas[0].addEventListener('touchstart', sketchpad_touchStart, false);
        canvas[0].addEventListener('touchmove', sketchpad_touchMove, false);
        rotate_button.addEventListener('click', function() {
            rotateIt();
        });
        reset_button.addEventListener('click', function() {
            resetslides();
        });
        zoomin_button.addEventListener('click', function() {
            zoomin();
        });
        zoomout_button.addEventListener('click', function() {
            zoomout();
        });
        morecontrast_button.addEventListener('click', function() {
            morecontrast();
        });
        lesscontrast_button.addEventListener('click', function() {
            lesscontrast();
        });
        morebrightness_button.addEventListener('click', function() {
            morebrightness();
        });
        lessbrightness_button.addEventListener('click', function() {
            lessbrightness();
        });
        morecolor_button.addEventListener('click', function() {
            morecolor();
        });
        lesscolor_button.addEventListener('click', function() {
            lesscolor();
        });
        cancel_button.addEventListener('click', function() {
            cancel();
        });

        let localStreamTrack = null; 

        // Helper function to lock down the Photo/Get button during live streaming
        function lockPhotoControl(disabledState) {
            const photoBtn = document.getElementById("text_02");
            if (photoBtn) {
                photoBtn.disabled = disabledState;
                const saveSpan = document.getElementById("savespan");
                const saveImg = document.getElementById("save");

                if (disabledState) {
                    photoBtn.style.backgroundColor = '#2c354a'; 
                    photoBtn.style.cursor = 'not-allowed';     
                    photoBtn.style.opacity = '0.6';
                    
                    if (saveImg) {
                        saveImg.style.opacity = '0.3';
                        saveImg.style.setProperty("background-color", "transparent", "important");
                    }
                    if (saveSpan) {
                        saveSpan.style.setProperty("background-color", "transparent", "important");
                        saveSpan.style.setProperty("color", "#242933", "important");
                    }
                } else {
                    photoBtn.style.backgroundColor = ''; 
                    photoBtn.style.cursor = 'pointer';     
                    photoBtn.style.opacity = '1.0';
                    
                    if (saveImg) {
                        saveImg.style.opacity = '1.0';
                        saveImg.style.backgroundColor = '';
                    }
                    if (saveSpan) {
                        saveSpan.style.backgroundColor = '';
                        saveSpan.style.color = '';
                    }
                }
            }
        }

        return_button.addEventListener('click', function() {
            const videoEl = document.getElementById('webcamVideo');

            if (rotate_button.style.visibility == 'visible') {
                // --- ABORT MODE ACTS CONTEXTUALLY DEPENDING ON THE SOURCE ---
                
                // 1. Wipe current workspace memory and hide editing tools immediately
                rotate_button.style.visibility = 'hidden';
                reset_button.style.visibility = 'hidden';
                slide_button.style.visibility = 'hidden';
                slide_button1.style.visibility = 'hidden';
                slide_button2.style.visibility = 'hidden';
                slide_button3.style.visibility = 'hidden';
                zoomin_button.style.visibility = 'hidden';
                zoomout_button.style.visibility = 'hidden';
                morecontrast_button.style.visibility = 'hidden';
                lesscontrast_button.style.visibility = 'hidden';
                morebrightness_button.style.visibility = 'hidden';
                lessbrightness_button.style.visibility = 'hidden';
                morecolor_button.style.visibility = 'hidden';
                lesscolor_button.style.visibility = 'hidden';

                if (imageSourceType === "file") {
                    preload = true; 
                    drawBack();
                    drawOldImage(); 

                    // Swap hardcoded text for your pre-translated variables!
                    text_02.innerHTML = '<img id="save" src="img/Upload_Icon_70x70.png" class="iconclass35" alt="Get" /><span id="savespan" class="image-text">' + textGet + '</span>';
                    text_06.innerHTML = '<img id="return" src="img/Camera_Icon_70x70.png" class="iconclass35" alt="Snap" /><span id="returnspan" class="image-text">' + textSnap + '</span>';
                    
                    save = document.getElementById("save");
                    savespan = document.getElementById("savespan");
                    returnit = document.getElementById("return");
                    returnspan = document.getElementById("returnspan");

                } else {
                    preload = false;
                    lockPhotoControl(true); 

                    // Uses the same variable strategy for hidden/disabled capture states
                    text_02.innerHTML = '<img id="save" src="img/Upload_Icon_70x70.png" class="iconclass35" alt="Get" style="opacity: 0.3; background-color: transparent !important;" /><span id="savespan" class="image-text" style="background-color: transparent !important; color: #242933 !important;">' + textGet + '</span>';
                    save = document.getElementById("save");
                    savespan = document.getElementById("savespan");

                    const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
                    if (isMobile) {
                        const mobCam = document.getElementById("camerapicture");
                        if (mobCam) mobCam.click();
                    } else {
                        if (videoEl) {
                            navigator.mediaDevices.getUserMedia({ 
                                video: { width: 350, height: 250, facingMode: "user" }, 
                                audio: false 
                            })
                            .then(function(stream) {
                                localStreamTrack = stream;
                                videoEl.srcObject = stream;
                                videoEl.style.display = 'block'; 
                                
                                // Switches the label to your translated Caption text!
                                text_06.innerHTML = '<img id="return" src="img/Camera_Icon_70x70.png" class="iconclass35" alt="Snap" /><span id="returnspan" class="image-text">' + textCaption + '</span>';
                                returnit = document.getElementById("return");
                                returnspan = document.getElementById("returnspan");
                            })
                            .catch(function(err) {
                                alert("Webcam stream access failed: " + err.message);
                            });
                        }
                    }
                }

            } else {
                // --- SNAP MODE ---
                // Inside your SNAP processing routine where State B captures the webcam frames, 
                // just ensure you record the imageSourceType flag right as the camera snaps frames:
                
                // ... inside videoEl.style.display !== 'none' -> inside else (State B): ...
                preload = false;
                imageSourceType = "webcam"; // Explicitly record that the source is a live webcam snap!
                
                // ... leave your existing State B canvas capture coordinates and drawing steps completely as they are below ...

                const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);

                if (isMobile) {
                    const mobCam = document.getElementById("camerapicture");
                    if (mobCam) mobCam.click();
                } else {
                    if (!videoEl) return;

                    if (videoEl.style.display === 'none') {
                        // STATE A: Launch live view video track
                        navigator.mediaDevices.getUserMedia({ 
                            video: { width: 350, height: 250, facingMode: "user" }, 
                            audio: false 
                        })
                        .then(function(stream) {
                            localStreamTrack = stream;
                            videoEl.srcObject = stream;
                            videoEl.style.display = 'block'; 
                            
                            // Style locks inside the inline HTML payload to clear background instantly using your variable
                            text_02.innerHTML = '<img id="save" src="img/Upload_Icon_70x70.png" class="iconclass35" alt="Get" style="opacity: 0.3; background-color: transparent !important;" /><span id="savespan" class="image-text" style="background-color: transparent !important; color: #242933 !important;">' + textGet + '</span>';
                            
                            // Apply background wrapper lock properties
                            lockPhotoControl(true);

                            const returnSpan = document.getElementById("returnspan");
                            if (returnSpan) returnSpan.innerText = textCaption; // Dynamic change using your variable!
                        })
                        .catch(function(err) {
                            alert("Webcam stream access failed: " + err.message);
                        });
                    } else {
                        // STATE B: Snap frame instantly, close tracking lines, and trigger the editor layout
                        preload = false;
                        
                        // 1. Draw the live video feed frames inside canvas 0 (ctx) so editing canvas picks it up right away
                        ctx.filter = "none";
                        ctx.drawImage(videoEl, 0, 0, 350, 250);
                        
                        // 2. CRITICAL INDEX CORRECTION: Extract the Base64 data from canvas 0 (ctx view)
                        img.src = canvas[0].toDataURL("image/jpeg");
                        
                        img.onload = function() {
                            // 1. INITIALIZE PROPERTIES AT 100% SCALE FIRST
                            // This guarantees your core drawImg() math has clean baseline variables
                            imageWidth = img.width;
                            imageHeight = img.height;
                            imw = imageWidth;
                            imh = imageHeight;
                            
                            // Establish the absolute center coordinates of the raw snapshot matrix
                            focx = Math.round(imageWidth / 2);
                            focy = Math.round(imageHeight / 2);

                            // 2. COMPUTE THE DYNAMIC CONTAINMENT RATIO
                            // Calculate the maximum scale needed to fit the image inside the 350x250 container box
                            let scaleX = ww / imageWidth;
                            let scaleY = hh / imageHeight;
                            let baseFitScale = Math.min(scaleX, scaleY) * 100;

                            // 3. APPLY THE 80% DOWNSCALE VISUAL REQUIREMENT
                            // Scale it down an extra 20% to leave a clean black border framing the face
                            scale = Math.round(baseFitScale * 0.80); 
                            scale2 = 100;
                            
                            // Set your visually active slider tracker to match logarithmic proportions
                            document.getElementById("myRange").value = 53; 

                            // 4. REFRESH AND DRAW
                            drawBack();
                            drawImg(); // This will now perfectly compute your 80% centering coordinates!
                            drawPaspartout();
                            
                            // Close video stream tracks cleanly
                            videoEl.style.display = 'none';
                            videoEl.srcObject = null;
                            
                            if (localStreamTrack) {
                                localStreamTrack.getTracks().forEach(track => track.stop());
                            }
                            
                            lockPhotoControl(false);
                            text_02.innerHTML = '<img id="save" src="img/Save_Icon_70x70.png" class="iconclass35" alt="Save" /><span id="savespan" class="image-text">Ready</span>';
                            save = document.getElementById("save");
                            savespan = document.getElementById("savespan");

                            // Dynamic modification seamlessly pointing straight to your translated textAbort variable value!
                            text_06.innerHTML = '<img id="return" src="img/Return_Icon_70x70.png" class="iconclass35" alt="Abort" /><span id="returnspan" class="image-text">' + textAbort + '</span>';
                            returnit = document.getElementById("return");
                            returnspan = document.getElementById("returnspan");


                            rotate_button.style.visibility = 'visible';
                            reset_button.style.visibility = 'visible';
                            slide_button.style.visibility = 'visible';
                            slide_button1.style.visibility = 'visible';
                            slide_button2.style.visibility = 'visible';
                            slide_button3.style.visibility = 'visible';
                            zoomin_button.style.visibility = 'visible';
                            zoomout_button.style.visibility = 'visible';
                            morecontrast_button.style.visibility = 'visible';
                            lesscontrast_button.style.visibility = 'visible';
                            morebrightness_button.style.visibility = 'visible';
                            lessbrightness_button.style.visibility = 'visible';
                            morecolor_button.style.visibility = 'visible';
                            lesscolor_button.style.visibility = 'visible';
                        };

                    }

                }
            }
        });




   
        //=======================================================
        //     Slider (ZOOM)
        //=======================================================    
        slide_button.oninput = function() 
        {
            var posit = this.value;   
            scale2=scale;
            scale = (100 * Math.pow(1.07, (50 - posit)));
            if (!(scale2==0)) {scale2=(scale / scale2); }; 
            co = Math.round(100 * Math.pow(1.02, (50 - slide_button1.value)));
            br = Math.round(100 * Math.pow(1.02, (50 - slide_button2.value)));
            sa = Math.round(100 * Math.pow(1.02, (50 - slide_button3.value)));
            ctx2.filter = "contrast(" + co.toString() + "%) brightness(" + br.toString() + "%) saturate(" + sa.toString() + "%)";   
            drawBack();
            drawImg();
            drawPaspartout(); 
        }   
        //=======================================================
        //     Slider1 (CONTRAST)
        //=======================================================    
        slide_button1.oninput = function() 
        {
            ////////////////////////////////////////
            /// CONTRAST ONLY ON CANVAS2
            ////////////////////////////////////////
            co = Math.round(100 * Math.pow(1.02, (50 - slide_button1.value)));
            br = Math.round(100 * Math.pow(1.02, (50 - slide_button2.value)));
            sa = Math.round(100 * Math.pow(1.02, (50 - slide_button3.value)));
            ctx2.filter = "contrast(" + co.toString() + "%) brightness(" + br.toString() + "%) saturate(" + sa.toString() + "%)";   
            drawBack();
            drawImg();
            drawPaspartout(); 
        }   





//batch 8

        //=======================================================
        //     Slider2 (BRIGHTNESS)
        //=======================================================    
        slide_button2.oninput = function() 
        {
            ////////////////////////////////////////
            /// BRIGHTNESS ONLY ON CANVAS 2
            ////////////////////////////////////////
            co = Math.round(100 * Math.pow(1.02, (50 - slide_button1.value)));
            br = Math.round(100 * Math.pow(1.02, (50 - slide_button2.value)));
            sa = Math.round(100 * Math.pow(1.02, (50 - slide_button3.value)));
            ctx2.filter = "contrast(" + co.toString() + "%) brightness(" + br.toString() + "%) saturate(" + sa.toString() + "%)";   
            drawBack();
            drawImg();
            drawPaspartout(); 
        }   
        //=======================================================
        //     Slider3 (SATURATION)
        //=======================================================    
        slide_button3.oninput = function() 
        {
            ////////////////////////////////////////
            /// SATURATION ONLY ON CANVAS 2
            ////////////////////////////////////////
            co = Math.round(100 * Math.pow(1.02, (50 - slide_button1.value)));
            br = Math.round(100 * Math.pow(1.02, (50 - slide_button2.value)));
            sa = Math.round(100 * Math.pow(1.02, (50 - slide_button3.value)));
            ctx2.filter = "contrast(" + co.toString() + "%) brightness(" + br.toString() + "%) saturate(" + sa.toString() + "%)";   
            drawBack();
            drawImg();
            drawPaspartout(); 
        }   
        //=======================================================
        //     USE! (save and exit)   NEW TEMPORARY CANVAS
        //=======================================================    
        save_button.addEventListener('click', function()
        {
            if (rotate_button.style.visibility == 'visible')
            {
                //alert("Saving");
                var canvas1 = document.createElement('canvas');
                canvas1.width = 140;
                canvas1.height = 140;
                canvas1.getContext('2d').drawImage(canvas[0],portx1, porty1, portx2-portx1, porty2-porty1,0,0,140, 140);
                var dataurl = canvas1.toDataURL();
                localStorage.setItem("image1", dataurl);  
                window.open(returnPage + ".php", "_self");
            } else
            {   ///////////////////////////////////////
                /// Load stage of the "save" button
                ///////////////////////////////////////
                load_button.click();           // here the dummy button calls the real button
            };
        });
        /////////////////////////////////////////////
        //     force load function and hide load button (workaround!!)
        /////////////////////////////////////////////
        //document.getElementById("loadpicture").disabled = true; 
        /////////////////////////////////////////////
        //     force load function and hide load button (workaround!!)
        /////////////////////////////////////////////
        rotate_button.style.visibility = 'hidden';
        reset_button.style.visibility = 'hidden';
        save_button.style.visibility = 'visible';

        slide_button.style.visibility = 'hidden';
        slide_button1.style.visibility = 'hidden';
        slide_button2.style.visibility = 'hidden';
        slide_button3.style.visibility = 'hidden';
        zoomin_button.style.visibility = 'hidden';
        zoomout_button.style.visibility = 'hidden';
        morecontrast_button.style.visibility = 'hidden';
        lesscontrast_button.style.visibility = 'hidden';
        morebrightness_button.style.visibility = 'hidden';
        lessbrightness_button.style.visibility = 'hidden';
        morecolor_button.style.visibility = 'hidden';
        lesscolor_button.style.visibility = 'hidden';

        // Helper function to apply the dark blue-gray disabled styling configuration
        function disableSnapButton(debugReason) {
            return_button.disabled = true;
            return_button.style.visibility = 'visible'; 
            return_button.style.backgroundColor = '#2c354a'; // Sleek dark blue-gray button body
            return_button.style.cursor = 'not-allowed'; // Prohibited cursor symbol
            return_button.style.opacity = '0.6';
            
            // Force the child text container to blend seamlessly
            const snapText = document.getElementById("returnspan");
            if (snapText) {
                snapText.style.setProperty("background-color", "transparent", "important"); // Erase independent span background
                snapText.style.setProperty("color", "#242933", "important"); // Crisp very dark gray text to match the icon
            }

            // Fade out the image icon to look uniform with the dark gray aesthetic
            const snapImg = document.getElementById("return");
            if (snapImg) {
                snapImg.style.opacity = '0.3';
                snapImg.style.setProperty("background-color", "transparent", "important");
            }
        }

        // Helper function to restore standard layout features
        function enableSnapButton(debugReason) {
            return_button.disabled = false;
            return_button.style.visibility = 'visible';
            return_button.style.backgroundColor = ''; 
            return_button.style.cursor = 'pointer';
            return_button.style.opacity = '1.0';
            
            // Restore original CSS layout parameters when enabled
            const snapText = document.getElementById("returnspan");
            if (snapText) {
                snapText.style.backgroundColor = ''; 
                snapText.style.color = ''; 
            }

            const snapImg = document.getElementById("return");
            if (snapImg) {
                snapImg.style.opacity = '1.0';
                snapImg.style.backgroundColor = '';
            }
        }



        const isLocalhost = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';

        // 2. RUN MEDIA CHECK WITH ACTIVE NOTIFICATION ALERTS
        if (navigator.mediaDevices && navigator.mediaDevices.enumerateDevices) {
            navigator.mediaDevices.enumerateDevices()
                .then(function(devices) {
                    // Log out devices info to build an exact count string for the alert window
                    let totalDevicesCount = devices.length;
                    let videoInputsCount = 0;
                    let detailedDevicesSummary = "";

                    devices.forEach(function(device, index) {
                        if (device.kind === 'videoinput') {
                            videoInputsCount++;
                        }
                        detailedDevicesSummary += "\n[" + index + "] Type: " + device.kind + " | Label: " + (device.label || "EMPTY/BLOCKED");
                    });

                    // Debug Alert message summarizing what Brave reports to the engine
                    //alert("BRAVE HARDWARE REPORT:\nTotal Media Tracks: " + totalDevicesCount + "\nVideo Lenses (Cameras) Found: " + videoInputsCount + detailedDevicesSummary);

                    if (videoInputsCount > 0) {
                        enableSnapButton("Found " + videoInputsCount + " video input device(s).");
                    } else {
                        disableSnapButton("Zero video inputs found in device enumeration matrix.");
                    }
                })
                .catch(function(err) {
                    if (!isLocalhost) {
                        enableSnapButton("API Error caught (" + err.message + ") but running on remote mobile network.");
                    } else {
                        disableSnapButton("API Error caught: " + err.message);
                    }
                });
        } else {
            if (!isLocalhost) {
                enableSnapButton("Navigator API not supported by this browser engine, keeping active for mobile.");
            } else {
                disableSnapButton("Navigator API missing completely on local environment.");
            }
        }

        document.getElementById("canvas").addEventListener("wheel", myFunction);
        document.getElementById("canvas").addEventListener("mousewheel", myFunction);
        document.getElementById("canvas").addEventListener("DOMMouseScroll", myFunction);
	} else 
    {
        alert("Browser can't store data. Please use other browser.");
    };
}


window.addEventListener("keydown", function(e) {
    // space, page up, page down and arrow keys:
    if([32, 33, 34, 37, 38, 39, 40].indexOf(e.keyCode) > -1) {
        e.preventDefault();
    }
}, false);
main();


/////////////////////////////////////////////
// END
/////////////////////////////////////////////
