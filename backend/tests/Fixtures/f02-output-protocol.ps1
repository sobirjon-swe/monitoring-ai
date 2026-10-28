# State-machine experiment only. A publish request is not proof of a committed receipt.
Add-Type -TypeDefinition @'
using System;
using System.Text.RegularExpressions;
public sealed class F02OutputProtocol {
 public string State { get; private set; }
 public F02OutputProtocol() { State="new"; }
 public void Prepare(string volume, string parent, string file, string hash) {
  if(State != "new") throw new InvalidOperationException("state refused");
  if(!Regex.IsMatch(volume ?? "", "^[0-9a-f]{8}$") || !Regex.IsMatch(parent ?? "", "^[0-9a-f]{16}$") || !Regex.IsMatch(file ?? "", "^[0-9a-f]{16}$") || !Regex.IsMatch(hash ?? "", "^[0-9a-f]{64}$")) throw new ArgumentException("metadata refused");
  State="prepared";
 }
 public void Command(string command) {
  if(command != "publish" && command != "abort") throw new ArgumentException("command refused");
  if(State != "prepared") throw new InvalidOperationException("state refused");
  State=command == "abort" ? "aborted" : "publish_requested";
 }
 public void ReadCommand(System.IO.Stream input) {
  byte[] frame=new byte[16]; int used=0;
  while(true) { int value=input.ReadByte(); if(value < 0) throw new System.IO.EndOfStreamException("incomplete frame"); if(value == 10) break; if(value < 0x20 || value > 0x7e || used == frame.Length) throw new ArgumentException("frame refused"); frame[used++]=(byte)value; }
  if(input.ReadByte() != -1) throw new ArgumentException("trailing frame refused");
  Command(System.Text.Encoding.ASCII.GetString(frame,0,used));
 }
}
'@
