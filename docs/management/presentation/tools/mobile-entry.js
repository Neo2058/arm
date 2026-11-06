// Demonstration wrapper: actual native screens + synthetic, memory-only API replies.
// Copied temporarily to mobile/.management-demo-entry.js for Metro resolution.
import React,{useState} from 'react';
import {View,Text,Pressable} from 'react-native';
import {registerRootComponent} from 'expo';
import {NavigationContainer,DarkTheme} from '@react-navigation/native';
import {createNativeStackNavigator} from '@react-navigation/native-stack';
import DocumentsScreen from './src/screens/DocumentsScreen';
import JournalScreen from './src/screens/JournalScreen';
import TrainingRequestsScreen from './src/screens/TrainingRequestsScreen';
import {DisciplineProvider} from './src/discipline/context';
import {client} from './src/api';
import fixtures from './.management-demo-api.json';
global.fetch=async()=>{throw new Error('Demonstration: network disabled');};
client.defaults.adapter=async config=>{
 const key=config.url.split('?')[0];
 if(config.method!=='get'||!fixtures[key])throw new Error('Demonstration: request blocked '+key);
 return {data:fixtures[key],status:200,statusText:'OK',headers:{},config};
};
const Stack=createNativeStackNavigator();
function Demo(){const [screen,setScreen]=useState('Journal');
 return <DisciplineProvider user={{id:'1',role:'instructor'}}><View style={{flex:1,backgroundColor:'#0b1018',paddingTop:28}}>
 <Text style={{backgroundColor:'#ffbe65',color:'#182334',textAlign:'center',fontSize:12,padding:5}}>УЧЕБНЫЕ ДАННЫЕ · ДЕМОНСТРАЦИОННЫЙ СТЕНД</Text>
 <View style={{flexDirection:'row',justifyContent:'space-evenly',padding:10}}>{[['Journal','Журнал'],['ATZ','АТЗ'],['Documents','Документы']].map(([id,label])=><Pressable key={id} onPress={()=>setScreen(id)}><Text style={{color:screen===id?'#fb923c':'#cbd5e1',padding:6,fontSize:16}}>{label}</Text></Pressable>)}</View>
 <NavigationContainer key={screen} theme={DarkTheme}><Stack.Navigator initialRouteName={screen} screenOptions={{headerShown:false}}><Stack.Screen name="Journal" component={JournalScreen}/><Stack.Screen name="ATZ" component={TrainingRequestsScreen}/><Stack.Screen name="Documents" component={DocumentsScreen}/></Stack.Navigator></NavigationContainer>
 </View></DisciplineProvider>;
}
registerRootComponent(Demo);
