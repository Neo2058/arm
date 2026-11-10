#include <QApplication>
#include <QTimer>
#include <QDate>
#include <QDir>
#include <QPainter>
#include <QJsonDocument>
#include <QJsonArray>
#include <QJsonObject>
#include <QFile>
#include <QDialog>
#include <QElapsedTimer>
#include "arm/gui/MainWindow.hpp"
int main(int argc,char** argv){
 QApplication app(argc,argv);app.setApplicationName("ARM-NRD-VIDEO-DEMO");
 arm::gui::MainWindow window;window.resize(1520,940);window.show();
 QDir().mkpath("/private/tmp/arm-video-desktop/frames");
 QElapsedTimer elapsed;elapsed.start();QJsonArray frames;int number=0;
 QTimer capture;capture.setInterval(250);
 QObject::connect(&capture,&QTimer::timeout,[&](){
  QPixmap canvas(1600,1000);canvas.fill(QColor("#0b1018"));QPainter painter(&canvas);
  auto base=window.grab();painter.drawPixmap((1600-base.width())/2,(1000-base.height())/2,base);
  if(auto* modal=QApplication::activeModalWidget()){auto pix=modal->grab();painter.drawPixmap((1600-pix.width())/2,(1000-pix.height())/2,pix);}
  painter.end();QString file=QString("frame-%1.png").arg(number++,5,10,QChar('0'));canvas.save("/private/tmp/arm-video-desktop/frames/"+file);
  frames.append(QJsonObject{{"file",file},{"time_seconds",elapsed.elapsed()/1000.0},{"scene","L01"}});
 });capture.start();
 QTimer::singleShot(3500,[&](){auto edit=window.findChild<QLineEdit*>();if(edit)edit->setText("Учебный машинист 03");});
 QTimer::singleShot(6500,[&](){auto edit=window.findChild<QLineEdit*>();if(edit)edit->clear();});
 QTimer::singleShot(8500,[&](){QMetaObject::invokeMethod(&window,"openCalendarDialog");});
 QTimer::singleShot(12000,[&](){if(auto* modal=QApplication::activeModalWidget())modal->close();});
 QTimer::singleShot(14000,[&](){QMetaObject::invokeMethod(&window,"openBreakdownCatalog");});
 QTimer::singleShot(17500,[&](){if(auto* modal=QApplication::activeModalWidget())modal->close();});
 QTimer::singleShot(19500,[&](){capture.stop();QFile out("/private/tmp/arm-video-desktop/frames/manifest.json");out.open(QIODevice::WriteOnly);out.write(QJsonDocument(QJsonObject{{"frames",frames},{"duration_seconds",elapsed.elapsed()/1000.0},{"scenes",QJsonArray{QJsonObject{{"start_seconds",0},{"code","L01"},{"title","Локальный ARM-NRD · сетка, поиск, календарь и разбивки"}}}}}).toJson());app.quit();});
 return app.exec();
}
